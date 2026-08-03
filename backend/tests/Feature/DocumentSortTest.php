<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\DocumentSeries;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\PaymentMethod;
use App\Models\Receipt;
use App\Models\User;
use App\Models\VatRate;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * GET /api/documents — whitelistelt rendezés (`sort_by` / `sort_dir`).
 *
 * A tesztek VALÓDI HTTP-kérés úton futnak (nem a ListSort osztályt fake-elve),
 * mert az injektálás-védelem és a company-scope megőrzése is csak a teljes
 * kérés-úton mérhető: a rendezés a company-scope-olt union-lekérdezés KÖRÉ épült
 * származtatott táblán fut.
 */
class DocumentSortTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;
    private static int $docSeq = 0;

    private Company $company;
    private Partner $partner;
    private PaymentMethod $paymentMethod;
    private VatRate $vatRate;
    private DocumentSeries $series;
    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();
        Queue::fake();

        [$this->company, $this->partner, $this->paymentMethod, $this->vatRate, $this->series] = $this->makeCompanyFixtures('Rendezés Kft.');

        $this->superadmin = User::create([
            'name' => 'Rendezés Admin', 'email' => 'doc.sort.'.self::$seq.'@example.com',
            'password' => bcrypt('password'), 'is_superadmin' => true,
        ]);
        $this->superadmin->companies()->attach($this->company->id, ['is_default' => true]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Alapértelmezés — a rendezés bevezetése NEM változtathat a meglévő viselkedésen
    // ══════════════════════════════════════════════════════════════════════════

    public function test_without_sort_params_the_previous_default_order_is_kept(): void
    {
        $this->makeInvoice(['issue_date' => '2026-01-10', 'gross_total' => 300]);
        $this->makeInvoice(['issue_date' => '2026-03-10', 'gross_total' => 100]);
        $this->makeInvoice(['issue_date' => '2026-02-10', 'gross_total' => 200]);

        $dates = $this->issueDates([]);

        $this->assertSame(['2026-03-10', '2026-02-10', '2026-01-10'], $dates, 'Alapértelmezés: kelt szerint csökkenő');
    }

    public function test_unknown_sort_column_silently_falls_back_to_the_default_order(): void
    {
        $this->makeInvoice(['issue_date' => '2026-01-10']);
        $this->makeInvoice(['issue_date' => '2026-03-10']);
        $this->makeInvoice(['issue_date' => '2026-02-10']);

        $dates = $this->issueDates(['sort_by' => 'nincs_ilyen_oszlop', 'sort_dir' => 'asc']);

        $this->assertSame(['2026-03-10', '2026-02-10', '2026-01-10'], $dates, 'Ismeretlen kulcs: némán vissza az alapértelmezésre, nem hiba');
    }

    public function test_invalid_sort_direction_falls_back_to_descending_but_keeps_the_requested_column(): void
    {
        $this->makeInvoice(['gross_total' => 100]);
        $this->makeInvoice(['gross_total' => 300]);
        $this->makeInvoice(['gross_total' => 200]);

        $rows = $this->documentsAs(['sort_by' => 'gross', 'sort_dir' => 'HÁTRAFELÉ'])['data'];
        $gross = array_map(fn ($row) => (float) $row['gross_total'], $rows);

        $this->assertSame([300.0, 200.0, 100.0], $gross, 'Érvénytelen irány: alapértelmezett (csökkenő), de a KÉRT oszlopon');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Whitelist — SQL-injektálás elleni védelem
    // ══════════════════════════════════════════════════════════════════════════

    #[DataProvider('injectionAttempts')]
    public function test_sql_injection_attempts_in_sort_params_do_not_break_the_endpoint(string $by, string $dir): void
    {
        $this->makeInvoice(['issue_date' => '2026-01-10']);
        $this->makeInvoice(['issue_date' => '2026-02-10']);

        $dates = $this->issueDates(['sort_by' => $by, 'sort_dir' => $dir]);

        // A lekérdezés lefut (200), az injektált töredék nem kerül a SQL-be, és
        // az eredmény az alapértelmezett rendezés.
        $this->assertSame(['2026-02-10', '2026-01-10'], $dates);
    }

    public static function injectionAttempts(): array
    {
        return [
            'oszlopnév-alapú kísérlet'   => ['issue_date; DROP TABLE invoices; --', 'asc'],
            'union-kísérlet'             => ['issue_date UNION SELECT 1', 'asc'],
            'irány-alapú kísérlet'       => ['issue_date', 'asc; DROP TABLE invoices; --'],
            'alkérdés az irányban'       => ['gross', '(SELECT 1)'],
            'üres paraméterek'           => ['', ''],
        ];
    }

    public function test_the_invoices_table_still_exists_after_an_injection_attempt(): void
    {
        $this->makeInvoice([]);

        $this->issueDates(['sort_by' => 'issue_date; DROP TABLE invoices; --']);

        // Az injektálási kísérlet nem futtatott le semmit — a tábla és a sor megvan.
        $this->assertDatabaseCount('invoices', 1);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Helyes rendezés — irányonként és oszloponként
    // ══════════════════════════════════════════════════════════════════════════

    public function test_sorting_by_gross_ascending_and_descending(): void
    {
        $this->makeInvoice(['gross_total' => 200]);
        $this->makeInvoice(['gross_total' => 100]);
        $this->makeInvoice(['gross_total' => 300]);

        $asc = array_map(fn ($r) => (float) $r['gross_total'], $this->documentsAs(['sort_by' => 'gross', 'sort_dir' => 'asc'])['data']);
        $desc = array_map(fn ($r) => (float) $r['gross_total'], $this->documentsAs(['sort_by' => 'gross', 'sort_dir' => 'desc'])['data']);

        $this->assertSame([100.0, 200.0, 300.0], $asc);
        $this->assertSame([300.0, 200.0, 100.0], $desc);
    }

    public function test_sorting_by_partner_name_uses_the_joined_column(): void
    {
        [, $partnerB] = $this->makePartner('Zeta Partner');
        [, $partnerA] = $this->makePartner('Alfa Partner');

        $this->makeInvoice(['partner_id' => $partnerB->id]);
        $this->makeInvoice(['partner_id' => $partnerA->id]);

        $names = array_map(
            fn ($r) => $r['partner_name'],
            $this->documentsAs(['sort_by' => 'partner', 'sort_dir' => 'asc'])['data']
        );

        $this->assertSame('Alfa Partner', $names[0]);
        $this->assertSame('Zeta Partner', $names[1]);
    }

    public function test_sorting_by_issue_date_ascending_reverses_the_default(): void
    {
        $this->makeInvoice(['issue_date' => '2026-01-10']);
        $this->makeInvoice(['issue_date' => '2026-03-10']);
        $this->makeInvoice(['issue_date' => '2026-02-10']);

        $this->assertSame(
            ['2026-01-10', '2026-02-10', '2026-03-10'],
            $this->issueDates(['sort_by' => 'issue_date', 'sort_dir' => 'asc'])
        );
    }

    /**
     * A nyugtának nincs fizetési határideje (backend: `NULL::date AS due_date`) —
     * az üres cellák egyik irányban sem kerülhetnek a lista tetejére (NULLS LAST).
     */
    public function test_null_values_are_ordered_last_in_both_directions(): void
    {
        $this->makeInvoice(['due_date' => '2026-05-01']);
        $this->makeReceipt([]); // due_date = NULL

        foreach (['asc', 'desc'] as $dir) {
            $rows = $this->documentsAs(['sort_by' => 'due_date', 'sort_dir' => $dir])['data'];

            $this->assertNotNull($rows[0]['due_date'], "A NULL due_date nem lehet elöl ({$dir})");
            $this->assertNull($rows[1]['due_date'], "A NULL due_date a lista végén ({$dir})");
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Multi-company izoláció — a rendezés nem kerülheti meg a company-scope-ot
    // ══════════════════════════════════════════════════════════════════════════

    public function test_sorting_does_not_leak_another_companys_documents(): void
    {
        [$companyB, $partnerB, $pmB, $vatB, $seriesB] = $this->makeCompanyFixtures('Rendezés Isol Kft.');
        // Szándékosan a legnagyobb összeg és a legkésőbbi dátum a MÁSIK cégen:
        // ha a rendezés kikerülné a scope-ot, ez a sor kerülne mindkét irányban elöl.
        $this->makeInvoiceFor($companyB, $partnerB, $pmB, $vatB, $seriesB, ['gross_total' => 999999, 'issue_date' => '2030-01-01']);
        $this->makeInvoice(['gross_total' => 1000, 'issue_date' => '2026-01-01']);

        foreach ([['gross', 'desc'], ['gross', 'asc'], ['issue_date', 'desc']] as [$by, $dir]) {
            $data = $this->documentsAs(['sort_by' => $by, 'sort_dir' => $dir]);

            $this->assertSame(1, $data['meta']['total'], "Csak a saját cég sora ({$by}/{$dir})");
            $this->assertEqualsWithDelta(1000.0, (float) $data['data'][0]['gross_total'], 0.001);
        }
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Lapozás — a rendezés a TELJES halmazra érvényes, nem csak az oldalon belül
    // ══════════════════════════════════════════════════════════════════════════

    public function test_sort_applies_across_pages_not_only_within_a_page(): void
    {
        // A perPage() whitelistjének legkisebb értéke 20 — a lapozás
        // méréséhez ennél több sor kell.
        for ($i = 1; $i <= 25; $i++) {
            $this->makeInvoice(['gross_total' => $i * 100]);
        }

        $firstPage = $this->documentsAs(['sort_by' => 'gross', 'sort_dir' => 'asc', 'per_page' => 20, 'page' => 1])['data'];
        $secondPage = $this->documentsAs(['sort_by' => 'gross', 'sort_dir' => 'asc', 'per_page' => 20, 'page' => 2])['data'];

        $this->assertEqualsWithDelta(100.0, (float) $firstPage[0]['gross_total'], 0.001, 'A globálisan legkisebb az 1. oldal tetején');
        $this->assertCount(5, $secondPage);
        $this->assertEqualsWithDelta(2500.0, (float) $secondPage[4]['gross_total'], 0.001, 'A globálisan legnagyobb a 2. oldal alján');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Az export ugyanazt a rendezést kapja
    // ══════════════════════════════════════════════════════════════════════════

    public function test_export_honours_the_same_sort_parameters(): void
    {
        $this->makeInvoice(['gross_total' => 200]);
        $this->makeInvoice(['gross_total' => 100]);
        $this->makeInvoice(['gross_total' => 300]);

        $csv = $this->exportAs(['sort_by' => 'gross', 'sort_dir' => 'asc']);
        $rows = array_values(array_filter(explode("\n", trim($csv))));
        array_shift($rows); // fejléc

        $gross = array_map(fn ($line) => (float) explode(';', $line)[9], $rows);

        $this->assertSame([100.0, 200.0, 300.0], $gross);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /** @return string[] */
    private function issueDates(array $query): array
    {
        return array_map(fn ($row) => $row['issue_date'], $this->documentsAs($query)['data']);
    }

    private function documentsAs(array $query): array
    {
        return $this->authed()
            ->getJson('/api/documents?'.http_build_query($query))
            ->assertOk()
            ->json();
    }

    private function exportAs(array $query): string
    {
        $response = $this->authed()
            ->get('/api/documents/export?'.http_build_query($query))
            ->assertOk();

        return $response->streamedContent();
    }

    private function authed(): self
    {
        return $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->company->id])
            ->withHeader('X-Company-Id', (string) $this->company->id);
    }

    /** @return array{0: Company, 1: Partner} */
    private function makePartner(string $name): array
    {
        app(CurrentCompany::class)->set($this->company->id);

        $partner = Partner::create([
            'type' => 'customer', 'name' => $name,
            'billing_postal_code' => '1000', 'billing_city' => 'Budapest', 'billing_address_line' => 'Fő u. 1.',
            'default_currency' => 'HUF',
        ]);

        app(CurrentCompany::class)->clear();

        return [$this->company, $partner];
    }

    /** @return array{0: Company, 1: Partner, 2: PaymentMethod, 3: VatRate, 4: DocumentSeries} */
    private function makeCompanyFixtures(string $namePrefix): array
    {
        self::$seq++;

        $company = Company::create([
            'name' => $namePrefix.' '.self::$seq,
            'tax_number' => '7777777'.self::$seq.'-2-41',
            'registration_number' => '02-09-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code' => '1000', 'city' => 'Budapest', 'address_line' => 'Rendezés u. '.self::$seq.'.',
            'base_currency' => 'HUF',
        ]);

        app(CurrentCompany::class)->set($company->id);

        $partner = Partner::create([
            'type' => 'customer', 'name' => 'Rendezés Partner '.self::$seq,
            'billing_postal_code' => '1000', 'billing_city' => 'Budapest', 'billing_address_line' => 'Fő u. 1.',
            'default_currency' => 'HUF',
        ]);

        $paymentMethod = PaymentMethod::create(['code' => 'SRTCASH'.self::$seq, 'name' => 'Készpénz', 'is_active' => true]);
        $vatRate = VatRate::create(['name' => 'ÁFA 27% S'.self::$seq, 'rate_percent' => 27.00, 'nav_code' => '27', 'is_active' => true]);

        $series = DocumentSeries::withoutGlobalScope('company')->create([
            'company_id' => $company->id, 'document_type' => DocumentType::Invoice,
            'prefix' => 'SR'.self::$seq, 'reset_yearly' => true, 'next_number' => 1,
        ]);

        app(CurrentCompany::class)->clear();

        return [$company, $partner, $paymentMethod, $vatRate, $series];
    }

    private function makeInvoice(array $overrides = []): Invoice
    {
        return $this->makeInvoiceFor($this->company, $this->partner, $this->paymentMethod, $this->vatRate, $this->series, $overrides);
    }

    private function makeInvoiceFor(
        Company $company, Partner $partner, PaymentMethod $paymentMethod, VatRate $vatRate, DocumentSeries $series, array $overrides = []
    ): Invoice {
        self::$docSeq++;

        $grossTotal = $overrides['gross_total'] ?? 1270.0;
        $netTotal = $overrides['net_total'] ?? round($grossTotal / 1.27, 2);
        $vatTotal = $overrides['vat_total'] ?? round($grossTotal - $netTotal, 2);

        $invoice = Invoice::create(array_merge([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'document_series_id' => $series->id,
            'invoice_number' => sprintf('SR-%s-%06d', now()->format('Ym'), self::$docSeq),
            'issue_date' => now()->toDateString(),
            'fulfillment_date' => now()->toDateString(),
            'due_date' => now()->addDays(14)->toDateString(),
            'currency' => 'HUF',
            'exchange_rate' => 1.0,
            'exchange_rate_date' => now()->toDateString(),
            'payment_method_id' => $paymentMethod->id,
            'status' => InvoiceStatus::Issued,
            'payment_status' => PaymentStatus::Open,
            'net_total' => $netTotal,
            'vat_total' => $vatTotal,
            'gross_total' => $grossTotal,
            'gross_total_base_currency' => $grossTotal,
        ], $overrides));

        $invoice->items()->create([
            'description' => 'Teszt tétel', 'quantity' => 1.0, 'unit' => 'db',
            'unit_price' => (float) $invoice->net_total, 'vat_rate_id' => $vatRate->id,
            'net_amount' => (float) $invoice->net_total, 'vat_amount' => (float) $invoice->vat_total,
            'gross_amount' => (float) $invoice->gross_total, 'sort_order' => 0,
        ]);

        return $invoice;
    }

    private function makeReceipt(array $overrides = []): Receipt
    {
        self::$docSeq++;
        $grossTotal = $overrides['gross_total'] ?? 500.0;

        return Receipt::create(array_merge([
            'company_id' => $this->company->id,
            'partner_id' => $this->partner->id,
            'document_series_id' => $this->series->id,
            'receipt_number' => sprintf('SRNY-%s-%06d', now()->format('Ym'), self::$docSeq),
            'issue_date' => now()->toDateString(),
            'currency' => 'HUF',
            'exchange_rate' => 1.0,
            'exchange_rate_date' => now()->toDateString(),
            'payment_method_id' => $this->paymentMethod->id,
            'status' => 'issued',
            'net_total' => round($grossTotal / 1.27, 2),
            'vat_total' => round($grossTotal - $grossTotal / 1.27, 2),
            'gross_total' => $grossTotal,
        ], $overrides));
    }
}
