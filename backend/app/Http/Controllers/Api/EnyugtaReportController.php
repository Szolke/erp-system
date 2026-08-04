<?php

namespace App\Http\Controllers\Api;

use App\Enums\ReceiptReportStatus;
use App\Http\Controllers\Concerns\EnforcesCompanyScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\ReceiptReportResource;
use App\Models\ReceiptReport;
use App\Support\CurrentCompany;
use App\Support\ListSort;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Olvasó végpontok a NAV eNyugta napi jelentésekhez (2. fázis). Beküldés,
 * újraküldés, státuszváltás NINCS itt — az a 3. fázis feladata.
 *
 * @group NAV eNyugta — jelentések
 */
class EnyugtaReportController extends Controller
{
    use EnforcesCompanyScope;

    /** Rendezhető oszlopok (l. App\Support\ListSort). A `gross_total` a
     *  `total_gross` oszlopra rendez (a frontend-kulcs a bizonylatlista
     *  `gross`/`gross_huf` mintáját követi, nem az DB-oszlopnevet). */
    private const SORTABLE_COLUMNS = [
        'report_date'   => 'report_date',
        'type'          => 'type',
        'status'        => 'status',
        'receipt_count' => 'receipt_count',
        'gross_total'   => 'total_gross',
    ];

    private const DEFAULT_SORT_KEY = 'report_date';

    private const SORT_TIE_BREAKERS = ['id DESC'];

    /** GET /api/enyugta/reports — lista, szűrhető dátumtartományra és státuszra. */
    public function index(Request $request, CurrentCompany $currentCompany)
    {
        $this->authorize('enyugta.view');

        $filters = $request->validate([
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'status' => ['nullable', Rule::enum(ReceiptReportStatus::class)],
        ]);

        $reports = ReceiptReport::where('company_id', $currentCompany->id())
            ->when($filters['date_from'] ?? null, fn ($q, $v) => $q->where('report_date', '>=', $v))
            ->when($filters['date_to'] ?? null, fn ($q, $v) => $q->where('report_date', '<=', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByRaw($this->orderBySql($request))
            ->get();

        return ReceiptReportResource::collection($reports);
    }

    private function orderBySql(Request $request): string
    {
        return ListSort::fromRequest($request, self::SORTABLE_COLUMNS, self::DEFAULT_SORT_KEY)
            ->toOrderBySql(self::SORT_TIE_BREAKERS);
    }

    /** GET /api/enyugta/reports/{report} — részletek, kategóriánkénti sorokkal. */
    public function show(ReceiptReport $report): ReceiptReportResource
    {
        $this->authorize('enyugta.view');
        $this->assertBelongsToCurrentCompany($report);

        return new ReceiptReportResource($report->load('lines'));
    }

    /**
     * GET /api/enyugta/reports/{report}/export — CSV (D7 vészkijárat-export a
     * KOBAK-portálon való kézi rögzítéshez, mivel a gépi interfész bázis-URL-je
     * nincs publikálva, l. docs/nav-enyugta-spec-jegyzetek.md).
     */
    public function export(ReceiptReport $report): StreamedResponse
    {
        $this->authorize('enyugta.view');
        $this->assertBelongsToCurrentCompany($report);

        $report->load('lines');

        $filename = sprintf('enyugta-jelentes-%s-%d.csv', $report->report_date->format('Y-m-d'), $report->id);

        return response()->streamDownload(function () use ($report) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM — enélkül az Excel elrontja az ékezeteket
            fputcsv($handle, ['Nap', 'ÁFA-kategória', 'Nettó', 'ÁFA', 'Bruttó', 'Nyugtaszám'], ';');

            foreach ($report->lines as $line) {
                fputcsv($handle, [
                    $report->report_date->format('Y-m-d'),
                    $line->nav_receipt_category,
                    $line->net_amount,
                    $line->vat_amount,
                    $line->gross_amount,
                    $line->receipt_count,
                ], ';');
            }

            fputcsv($handle, [
                $report->report_date->format('Y-m-d'),
                'Összesen',
                $report->total_net,
                $report->total_vat,
                $report->total_gross,
                $report->receipt_count,
            ], ';');

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
