<?php

namespace App\Services\Enyugta;

use App\Enums\ReceiptReportStatus;
use App\Enums\ReceiptReportType;
use App\Models\Company;
use App\Models\Receipt;
use App\Models\ReceiptReport;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Napi nyugta-adatszolgáltatás összesítő legyártása (NAV eNyugta 2. fázis).
 *
 * D5: a nyugta a KIÁLLÍTÁS dátuma (`receipts.issue_date`) alapján kerül a
 * napba, Europe/Budapest időzóna szerint — NEM a fulfillment_date.
 * D6: a fejléc/sor-összegek a nyugta ITEMEKEN már tárolt nettó/áfa/bruttó
 * értékek összege, újraszámolás/újrakerekítés nélkül — csak a végösszeg kerül
 * egész forintra kerekítve (l. aggregate()). Nincs deviza-konverzió: nem-HUF
 * nyugta esetén a build() hibát dob (l. assertAllHuf()).
 * D4: az áfakategóriánkénti csoportosítás kulcsa a `vat_rates.nav_receipt_category`
 * — ha ez hiányzik egy érintett áfakulcsnál, a build() hibát dob, nem tippel
 * és nem hagyja ki csendben a sort.
 */
class ReceiptReportBuilder
{
    public function build(Company $company, CarbonInterface $date): ReceiptReport
    {
        $reportDate = $date->clone()->timezone('Europe/Budapest')->toDateString();

        return DB::transaction(function () use ($company, $reportDate) {
            // withoutGlobalScope('company'): a builder queue/console kontextusból is
            // hívható, ahol nincs CurrentCompany — a company_id-t mindig explicit adjuk
            // meg (a SendInvoiceToNavJob/InvoiceNumberGenerator mintája).
            $receipts = Receipt::withoutGlobalScope('company')
                ->where('company_id', $company->id)
                ->where('issue_date', $reportDate)
                ->with('items.vatRate')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $this->assertAllHuf($receipts);

            [$lineData, $totals] = $this->aggregate($receipts);

            $existingNormal = ReceiptReport::withoutGlobalScope('company')
                ->where('company_id', $company->id)
                ->where('report_date', $reportDate)
                ->where('type', ReceiptReportType::Normal->value)
                ->first();

            if ($existingNormal === null) {
                return $this->writeReport($company, $reportDate, ReceiptReportType::Normal, null, $lineData, $totals, $receipts);
            }

            if ($this->linesMatch($existingNormal, $lineData)) {
                return $existingNormal; // idempotens: nincs változás, nincs teendő
            }

            if ($existingNormal->status !== ReceiptReportStatus::Accepted) {
                // Még nem lett elfogadva (nincs ténylegesen beküldve a NAV felé) — a
                // meglévő draft/ready sor helyben frissíthető, nem immutábilis még.
                return $this->updateReport($existingNormal, $lineData, $totals, $receipts);
            }

            // D1: elfogadott (immutábilis) jelentés + időközi változás → korrekció,
            // a nap TELJES újraszámolt összesítésével, NEM a különbözettel.
            return $this->writeReport($company, $reportDate, ReceiptReportType::Correction, $existingNormal->id, $lineData, $totals, $receipts);
        });
    }

    /**
     * @param  Collection<int, Receipt>  $receipts
     */
    private function assertAllHuf(Collection $receipts): void
    {
        $nonHuf = $receipts->first(fn (Receipt $r) => $r->currency !== 'HUF');

        if ($nonHuf !== null) {
            throw new ReceiptReportBuildException(sprintf(
                'A(z) %s nyugta pénzneme "%s", nem HUF — a NAV eNyugta napi összesítő jelenleg kizárólag forintban kiállított nyugtákat tud feldolgozni, árfolyam-konverzió nélkül (l. docs/nav-enyugta-spec-jegyzetek.md). Devizás nyugták kezelése nincs implementálva.',
                $nonHuf->receipt_number,
                $nonHuf->currency,
            ));
        }
    }

    /**
     * Áfakategóriánként (D4) csoportosítja a nyugtatételeket, és forintra
     * kerekített (D6) fejléc-összegeket számol. A nyugtaszám (receipt_count)
     * kategóriánként a kategóriát tartalmazó DISZTINKT nyugták száma; fejléc
     * szinten a napon szereplő ÖSSZES nyugta száma (nem a kategóriánkénti
     * összeg, mert egy nyugta több kategóriában is szerepelhet).
     *
     * @param  Collection<int, Receipt>  $receipts
     * @return array{0: array<string, array{net_amount: float, vat_amount: float, gross_amount: float, receipt_count: int}>, 1: array{net: float, vat: float, gross: float, receipt_count: int}}
     */
    private function aggregate(Collection $receipts): array
    {
        $categoryTotals = [];

        foreach ($receipts as $receipt) {
            foreach ($receipt->items as $item) {
                $vatRate = $item->vatRate;
                $category = $vatRate?->nav_receipt_category;

                if ($category === null) {
                    throw new ReceiptReportBuildException(sprintf(
                        'A(z) "%s" áfakulcshoz (id=%d) nincs beállítva NAV eNyugta kategória (vat_rates.nav_receipt_category) — a nyugta-adatszolgáltatás nem generálható, amíg ez hiányzik. Állítsd be az áfakulcs NAV-kategóriáját (l. docs/progress.md eNyugta 1. fázis), majd futtasd újra.',
                        $vatRate?->name ?? 'ismeretlen',
                        $item->vat_rate_id,
                    ));
                }

                $categoryTotals[$category] ??= ['net' => 0.0, 'vat' => 0.0, 'gross' => 0.0, 'receipt_ids' => []];
                $categoryTotals[$category]['net'] += (float) $item->net_amount;
                $categoryTotals[$category]['vat'] += (float) $item->vat_amount;
                $categoryTotals[$category]['gross'] += (float) $item->gross_amount;
                $categoryTotals[$category]['receipt_ids'][$receipt->id] = true;
            }
        }

        $lineData = [];
        foreach ($categoryTotals as $category => $sums) {
            $lineData[$category] = [
                'net_amount' => round($sums['net']),
                'vat_amount' => round($sums['vat']),
                'gross_amount' => round($sums['gross']),
                'receipt_count' => count($sums['receipt_ids']),
            ];
        }
        ksort($lineData); // determinisztikus sorrend (kategórianév szerint)

        $totals = [
            'net' => round(array_sum(array_column($lineData, 'net_amount'))),
            'vat' => round(array_sum(array_column($lineData, 'vat_amount'))),
            'gross' => round(array_sum(array_column($lineData, 'gross_amount'))),
            'receipt_count' => $receipts->count(),
        ];

        return [$lineData, $totals];
    }

    /**
     * @param  array<string, array{net_amount: float, vat_amount: float, gross_amount: float, receipt_count: int}>  $lineData
     */
    private function linesMatch(ReceiptReport $report, array $lineData): bool
    {
        $existingLines = $report->lines()->get()->keyBy('nav_receipt_category');

        if ($existingLines->count() !== count($lineData)) {
            return false;
        }

        foreach ($lineData as $category => $sums) {
            $existing = $existingLines->get($category);

            if ($existing === null
                || (float) $existing->net_amount !== (float) $sums['net_amount']
                || (float) $existing->vat_amount !== (float) $sums['vat_amount']
                || (float) $existing->gross_amount !== (float) $sums['gross_amount']
                || (int) $existing->receipt_count !== (int) $sums['receipt_count']
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, array{net_amount: float, vat_amount: float, gross_amount: float, receipt_count: int}>  $lineData
     * @param  array{net: float, vat: float, gross: float, receipt_count: int}  $totals
     * @param  Collection<int, Receipt>  $receipts
     */
    private function writeReport(
        Company $company,
        string $reportDate,
        ReceiptReportType $type,
        ?int $originalReportId,
        array $lineData,
        array $totals,
        Collection $receipts,
    ): ReceiptReport {
        $report = ReceiptReport::create([
            'company_id' => $company->id,
            'report_date' => $reportDate,
            'type' => $type,
            'original_report_id' => $originalReportId,
            'status' => ReceiptReportStatus::Draft,
            'receipt_count' => $totals['receipt_count'],
            'total_net' => $totals['net'],
            'total_vat' => $totals['vat'],
            'total_gross' => $totals['gross'],
            'generated_at' => now(),
        ]);

        $this->writeLines($report, $lineData);
        $this->markReceiptsReported($receipts, $report);

        return $report;
    }

    /**
     * @param  array<string, array{net_amount: float, vat_amount: float, gross_amount: float, receipt_count: int}>  $lineData
     * @param  array{net: float, vat: float, gross: float, receipt_count: int}  $totals
     * @param  Collection<int, Receipt>  $receipts
     */
    private function updateReport(ReceiptReport $report, array $lineData, array $totals, Collection $receipts): ReceiptReport
    {
        $report->update([
            'receipt_count' => $totals['receipt_count'],
            'total_net' => $totals['net'],
            'total_vat' => $totals['vat'],
            'total_gross' => $totals['gross'],
            'generated_at' => now(),
        ]);

        $report->lines()->delete();
        $this->writeLines($report, $lineData);
        $this->markReceiptsReported($receipts, $report);

        return $report;
    }

    /**
     * @param  array<string, array{net_amount: float, vat_amount: float, gross_amount: float, receipt_count: int}>  $lineData
     */
    private function writeLines(ReceiptReport $report, array $lineData): void
    {
        foreach ($lineData as $category => $sums) {
            $report->lines()->create([
                'nav_receipt_category' => $category,
                'net_amount' => $sums['net_amount'],
                'vat_amount' => $sums['vat_amount'],
                'gross_amount' => $sums['gross_amount'],
                'receipt_count' => $sums['receipt_count'],
            ]);
        }
    }

    /**
     * D2: a már jelentett nyugta reported_at/receipt_report_id mezőin KÍVÜL
     * mindent zárol a Receipt-modell guardja — ez a metódus KIZÁRÓLAG ezt a
     * két mezőt írja, ezért a guard sosem akasztja meg (l. Receipt::booted(),
     * REPORT_LOCK_EXEMPT_FIELDS). Korrekciónál egy már jelentett nyugta
     * receipt_report_id-ja átmutat az ÚJ (korrekciós) jelentésre, a
     * reported_at viszont az EREDETI jelentési időpontján marad.
     *
     * @param  Collection<int, Receipt>  $receipts
     */
    private function markReceiptsReported(Collection $receipts, ReceiptReport $report): void
    {
        foreach ($receipts as $receipt) {
            if ($receipt->receipt_report_id === $report->id && $receipt->reported_at !== null) {
                continue; // már ehhez a jelentéshez van rendelve — nincs mit írni
            }

            $receipt->reported_at ??= now();
            $receipt->receipt_report_id = $report->id;
            $receipt->save();
        }
    }
}
