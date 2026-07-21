<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\ReceiptStatus;
use App\Models\Company;
use App\Models\DocumentSeries;
use App\Models\Group;
use App\Models\Module;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Receipt;
use App\Models\ReceiptReport;
use App\Models\User;
use App\Models\VatRate;
use App\Services\Enyugta\ReceiptReportBuilder;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * HTTP-szintű tesztek: GET /api/enyugta/reports, GET /api/enyugta/reports/{report},
 * GET /api/enyugta/reports/{report}/export. Fedi: jogosultság (enyugta.view),
 * multi-tenant izoláció, CSV-tartalom.
 */
class EnyugtaReportControllerTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;
    private static int $docSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    public function test_index_requires_enyugta_view_permission(): void
    {
        [$company, $user] = $this->makeCompanyWithUser([]); // nincs jog

        $response = $this->actingAs($user)->getJson('/api/enyugta/reports');

        $response->assertForbidden();
    }

    public function test_index_lists_reports_for_current_company_only(): void
    {
        [$companyA, $userA] = $this->makeCompanyWithUser(['enyugta.view']);
        [$companyB, $userB] = $this->makeCompanyWithUser(['enyugta.view']);

        $this->buildReportFor($companyA, '2026-07-10');
        $this->buildReportFor($companyB, '2026-07-10');

        $response = $this->actingAs($userA)->getJson('/api/enyugta/reports');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame($companyA->id, ReceiptReport::withoutGlobalScope('company')->find($data[0]['id'])->company_id);
    }

    public function test_index_filters_by_status(): void
    {
        [$company, $user] = $this->makeCompanyWithUser(['enyugta.view']);
        $report = $this->buildReportFor($company, '2026-07-10');
        $report->update(['status' => 'accepted']);

        $response = $this->actingAs($user)->getJson('/api/enyugta/reports?status=accepted');
        $response->assertOk();
        $this->assertCount(1, $response->json('data'));

        $response = $this->actingAs($user)->getJson('/api/enyugta/reports?status=rejected');
        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_show_returns_lines(): void
    {
        [$company, $user] = $this->makeCompanyWithUser(['enyugta.view']);
        $report = $this->buildReportFor($company, '2026-07-10');

        $response = $this->actingAs($user)->getJson("/api/enyugta/reports/{$report->id}");

        $response->assertOk();
        $response->assertJsonPath('data.id', $report->id);
        $response->assertJsonCount(1, 'data.lines');
        $response->assertJsonPath('data.lines.0.nav_receipt_category', '27%');
    }

    public function test_company_a_cannot_access_company_b_report(): void
    {
        [$companyA, $userA] = $this->makeCompanyWithUser(['enyugta.view']);
        [$companyB, $userB] = $this->makeCompanyWithUser(['enyugta.view']);

        $reportB = $this->buildReportFor($companyB, '2026-07-10');

        $response = $this->actingAs($userA)->getJson("/api/enyugta/reports/{$reportB->id}");

        $response->assertNotFound();
    }

    public function test_export_returns_csv_with_correct_content(): void
    {
        [$company, $user] = $this->makeCompanyWithUser(['enyugta.view']);
        $report = $this->buildReportFor($company, '2026-07-10');

        $response = $this->actingAs($user)->get("/api/enyugta/reports/{$report->id}/export");

        $response->assertOk();
        $response->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();
        $this->assertStringContainsString('Nap;ÁFA-kategória;Nettó;ÁFA;Bruttó;Nyugtaszám', $csv);
        $this->assertStringContainsString('2026-07-10;27%;10000.00;2700.00;12700.00;1', $csv);
        $this->assertStringContainsString('Összesen', $csv);
    }

    public function test_export_blocked_for_other_companys_report(): void
    {
        [$companyA, $userA] = $this->makeCompanyWithUser(['enyugta.view']);
        [$companyB, $userB] = $this->makeCompanyWithUser(['enyugta.view']);

        $reportB = $this->buildReportFor($companyB, '2026-07-10');

        $response = $this->actingAs($userA)->get("/api/enyugta/reports/{$reportB->id}/export");

        $response->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════

    /** @return array{0: Company, 1: User} */
    private function makeCompanyWithUser(array $permissionKeys): array
    {
        self::$seq++;

        $company = Company::withoutGlobalScope('company')->create([
            'name'                => 'eNyugta Report HTTP Kft. '.self::$seq,
            'tax_number'          => '7778889'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '1111',
            'city'                => 'Budapest',
            'address_line'        => 'Riport u. '.self::$seq.'.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
        ]);

        $module = Module::firstOrCreate(
            ['key' => 'enyugta'],
            ['name' => 'eNyugta', 'description' => 'test', 'version' => '1.0.0', 'is_core' => false, 'is_available' => true, 'sort_order' => 10]
        );
        $company->enabledModules()->attach($module->id, ['enabled' => true]);

        $user = User::create([
            'name' => 'User '.self::$seq,
            'email' => 'enyugta.report.user'.self::$seq.'@example.com',
            'password' => bcrypt('password'),
            'is_superadmin' => false,
            'default_company_id' => $company->id,
        ]);
        $company->users()->attach($user->id);

        app(CurrentCompany::class)->set($company->id);
        $group = Group::create(['company_id' => $company->id, 'name' => 'eNyugta csoport '.self::$seq]);
        $group->users()->attach($user->id);

        foreach ($permissionKeys as $key) {
            $permission = Permission::firstOrCreate(['key' => $key], ['module' => 'enyugta']);
            $group->permissions()->attach($permission->id);
        }

        app(CurrentCompany::class)->clear();

        return [$company, $user];
    }

    private function buildReportFor(Company $company, string $issueDate): ReceiptReport
    {
        app(CurrentCompany::class)->set($company->id);

        self::$seq++;

        $paymentMethod = PaymentMethod::create([
            'code' => 'CASH'.self::$seq,
            'name' => 'Készpénz',
            'is_active' => true,
        ]);

        $series = DocumentSeries::withoutGlobalScope('company')->create([
            'company_id' => $company->id,
            'document_type' => DocumentType::Receipt,
            'prefix' => 'NY',
            'reset_yearly' => true,
            'next_number' => 1,
        ]);

        $vatRate = VatRate::create([
            'name' => '27% '.self::$seq,
            'rate_percent' => 27.0,
            'nav_code' => 'CODE'.self::$seq,
            'nav_receipt_category' => '27%',
            'is_active' => true,
        ]);

        self::$docSeq++;

        $receipt = Receipt::create([
            'company_id' => $company->id,
            'document_series_id' => $series->id,
            'receipt_number' => sprintf('NY-%s-%06d', now()->format('Ym'), self::$docSeq),
            'issue_date' => $issueDate,
            'currency' => 'HUF',
            'exchange_rate' => 1.0,
            'exchange_rate_date' => $issueDate,
            'payment_method_id' => $paymentMethod->id,
            'status' => ReceiptStatus::Issued,
            'net_total' => 10000,
            'vat_total' => 2700,
            'gross_total' => 12700,
        ]);

        $receipt->items()->create([
            'description' => 'Teszt tétel',
            'quantity' => 1,
            'unit_price' => 10000,
            'vat_rate_id' => $vatRate->id,
            'net_amount' => 10000,
            'vat_amount' => 2700,
            'gross_amount' => 12700,
            'sort_order' => 0,
        ]);

        $report = app(ReceiptReportBuilder::class)->build($company, now()->parse($issueDate));

        app(CurrentCompany::class)->clear();

        return $report;
    }
}
