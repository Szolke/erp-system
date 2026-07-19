<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\InvoiceReportRequest;
use App\Http\Requests\ProductReportRequest;
use App\Http\Requests\ReceivablesAgingReportRequest;
use App\Http\Requests\VatSummaryReportRequest;
use App\Models\Company;
use App\Services\ReportService;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @group Kimutatások
 *
 * Modul kikapcsolt állapotában 403-at ad, NEM 404-et — szándékosan nincs
 * `module:reports` middleware a route-okon (az a meglévő konvenció szerint
 * mindig 404-et adna). Ehelyett a `report.view`/`report.export` kulcsok a
 * ReportsModule::permissions()-ben regisztráltak, és a Gate::before-ban futó
 * ModuleResolver::isAllowed() miatt kikapcsolt modulnál a $this->authorize()
 * hívás magától AuthorizationException-t (403) dob.
 */
class ReportController extends Controller
{
    private const REPORT_TYPES = ['invoices', 'products', 'receivables-aging', 'vat-summary'];

    public function __construct(private ReportService $reportService) {}

    public function invoices(InvoiceReportRequest $request, CurrentCompany $currentCompany)
    {
        $this->authorize('report.view');
        $company = Company::findOrFail($currentCompany->id());

        return response()->json($this->reportService->invoicesReport($company, $request->validated()));
    }

    public function products(ProductReportRequest $request, CurrentCompany $currentCompany)
    {
        $this->authorize('report.view');
        $company = Company::findOrFail($currentCompany->id());

        return response()->json($this->reportService->productsReport($company, $request->validated()));
    }

    public function receivablesAging(ReceivablesAgingReportRequest $request, CurrentCompany $currentCompany)
    {
        $this->authorize('report.view');
        $company = Company::findOrFail($currentCompany->id());

        return response()->json($this->reportService->receivablesAging($company, $request->validated()));
    }

    public function vatSummary(VatSummaryReportRequest $request, CurrentCompany $currentCompany)
    {
        $this->authorize('report.view');
        $company = Company::findOrFail($currentCompany->id());

        return response()->json($this->reportService->vatSummary($company, $request->validated()));
    }

    /** GET /api/reports/{report}/export?format=csv — ugyanazok a paraméterek, mint a megfelelő JSON-végpontnál. */
    public function export(string $report, Request $request, CurrentCompany $currentCompany): StreamedResponse
    {
        $this->authorize('report.export');

        if (! in_array($report, self::REPORT_TYPES, true)) {
            abort(404);
        }

        $company = Company::findOrFail($currentCompany->id());

        $requestClass = match ($report) {
            'invoices' => InvoiceReportRequest::class,
            'products' => ProductReportRequest::class,
            'receivables-aging' => ReceivablesAgingReportRequest::class,
            'vat-summary' => VatSummaryReportRequest::class,
        };
        $filters = $this->validateFilters($requestClass, $request);

        // Ugyanaz a Cache-kulcs (company_id + riport-név + paraméterek), mint a
        // JSON-végponton — az export a JSON-nal megegyező, esetleg már cache-elt
        // adatot kapja, nincs külön export-cache.
        $data = match ($report) {
            'invoices' => $this->reportService->invoicesReport($company, $filters),
            'products' => $this->reportService->productsReport($company, $filters),
            'receivables-aging' => $this->reportService->receivablesAging($company, $filters),
            'vat-summary' => $this->reportService->vatSummary($company, $filters),
        };

        [$headers, $rows] = $this->toCsvRows($report, $data);

        $from = $filters['from'] ?? $filters['as_of'] ?? 'export';
        $to = $filters['to'] ?? $filters['as_of'] ?? 'export';
        $filename = "reports-{$report}-{$from}-{$to}.csv";

        return response()->streamDownload(function () use ($headers, $rows) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF"); // UTF-8 BOM — enélkül az Excel elrontja az ékezeteket
            fputcsv($handle, $headers, ';'); // magyar Excel-locale: pontosvessző elválasztó
            foreach ($rows as $row) {
                fputcsv($handle, $row, ';');
            }
            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * Egy adott riport-FormRequest osztály rules()/withValidator()-ját futtatja
     * a jelenlegi kérés query-paramétereire, a normál (route-injektált) auto-
     * validáció nélkül — az export dinamikus {report} route-param miatt nem
     * tud egyetlen fix FormRequest-típust type-hintelni.
     */
    private function validateFilters(string $formRequestClass, Request $request): array
    {
        /** @var FormRequest $formRequest */
        $formRequest = $formRequestClass::createFrom($request);
        $formRequest->setContainer(app());
        $formRequest->validateResolved();

        return $formRequest->validated();
    }

    /** @return array{0: string[], 1: array<int, array>} */
    private function toCsvRows(string $report, array $data): array
    {
        return match ($report) {
            'invoices' => $this->invoicesToCsv($data),
            'products' => $this->productsToCsv($data),
            'receivables-aging' => $this->receivablesAgingToCsv($data),
            'vat-summary' => $this->vatSummaryToCsv($data),
        };
    }

    private function invoicesToCsv(array $data): array
    {
        $headers = ['Időszak', 'Számlák száma', 'Nettó (HUF)', 'ÁFA (HUF)', 'Bruttó (HUF)', 'Kiegyenlítve (HUF)', 'Kintlévő (HUF)'];
        $rows = array_map(fn ($p) => [
            $p['period'], $p['invoice_count'], $p['net_total'], $p['vat_total'],
            $p['gross_total'], $p['paid_total'], $p['outstanding_total'],
        ], $data['periods']);

        return [$headers, $rows];
    }

    private function productsToCsv(array $data): array
    {
        $headers = ['Termék', 'Mértékegység', 'Mennyiség', 'Nettó árbevétel (HUF)', 'Számlák száma', 'Átlagos egységár (HUF)'];
        $rows = array_map(fn ($i) => [
            $i['name'], $i['unit'], $i['quantity'], $i['net_revenue'], $i['invoice_count'], $i['average_unit_price'],
        ], $data['items']);

        return [$headers, $rows];
    }

    private function receivablesAgingToCsv(array $data): array
    {
        $headers = ['Partner', 'Nem lejárt (HUF)', '0-30 nap (HUF)', '31-60 nap (HUF)', '61-90 nap (HUF)', '90+ nap (HUF)', 'Összesen (HUF)'];
        $rows = array_map(fn ($p) => [
            $p['partner_name'], $p['not_due'], $p['band_0_30'], $p['band_31_60'], $p['band_61_90'], $p['band_90_plus'], $p['total'],
        ], $data['partners']);

        return [$headers, $rows];
    }

    private function vatSummaryToCsv(array $data): array
    {
        $headers = ['Időszak', 'ÁFA-kategória', 'NAV-kód', 'Nettó (HUF)', 'ÁFA (HUF)', 'Bruttó (HUF)'];
        $rows = array_map(fn ($i) => [
            $i['period'], $i['vat_category'], $i['nav_code'], $i['net_total'], $i['vat_total'], $i['gross_total'],
        ], $data['items']);

        return [$headers, $rows];
    }
}
