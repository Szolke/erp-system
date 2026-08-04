<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Module;
use App\Models\ReceiptReport;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/enyugta/reports — whitelistelt rendezés (`sort_by` / `sort_dir`).
 *
 * A `gross_total` frontend-kulcs a `total_gross` DB-oszlopra rendez —
 * l. EnyugtaReportController SORTABLE_COLUMNS.
 */
class EnyugtaReportSortTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private Company $company;
    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();

        $this->company = $this->makeCompany();
        $this->superadmin = $this->makeUser(superadmin: true);
        $this->superadmin->companies()->attach($this->company->id, ['is_default' => true]);

        $module = Module::create([
            'key' => 'enyugta', 'name' => 'eNyugta', 'description' => 'Test module enyugta',
            'version' => '1.0.0', 'is_core' => false, 'is_available' => true, 'sort_order' => 10,
        ]);
        $this->company->enabledModules()->attach($module->id, ['enabled' => true]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    public function test_without_sort_params_the_previous_default_order_is_kept(): void
    {
        $this->makeReport('2026-07-10');
        $this->makeReport('2026-07-20');
        $this->makeReport('2026-07-15');

        $dates = $this->datesAs([]);

        $this->assertSame(['2026-07-20', '2026-07-15', '2026-07-10'], $dates, 'Alapértelmezés: report_date szerint csökkenő');
    }

    public function test_unknown_sort_column_silently_falls_back_to_the_default_order(): void
    {
        $this->makeReport('2026-07-10');
        $this->makeReport('2026-07-20');

        $dates = $this->datesAs(['sort_by' => 'nincs_ilyen', 'sort_dir' => 'asc']);

        $this->assertSame(['2026-07-20', '2026-07-10'], $dates);
    }

    /** `gross_total` frontend-kulcs -> `total_gross` DB-oszlop. */
    public function test_sorting_by_gross_total_uses_the_total_gross_column(): void
    {
        $this->makeReport('2026-07-10', grossTotal: 30000);
        $this->makeReport('2026-07-11', grossTotal: 10000);
        $this->makeReport('2026-07-12', grossTotal: 20000);

        $totals = array_map(
            fn ($r) => (float) $r['total_gross'],
            $this->reportsAs(['sort_by' => 'gross_total', 'sort_dir' => 'asc'])['data']
        );

        $this->assertSame([10000.0, 20000.0, 30000.0], $totals);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /** @return string[] */
    private function datesAs(array $query): array
    {
        return array_map(fn ($row) => $row['report_date'], $this->reportsAs($query)['data']);
    }

    private function reportsAs(array $query): array
    {
        return $this->asAdmin($this->company)
            ->getJson('/api/enyugta/reports?'.http_build_query($query))
            ->assertOk()
            ->json();
    }

    private function makeReport(string $reportDate, float $grossTotal = 12700): ReceiptReport
    {
        self::$seq++;

        return ReceiptReport::create([
            'company_id' => $this->company->id,
            'report_date' => $reportDate,
            'type' => 'normal',
            'status' => 'draft',
            'receipt_count' => 1,
            'total_net' => round($grossTotal / 1.27, 2),
            'total_vat' => round($grossTotal - $grossTotal / 1.27, 2),
            'total_gross' => $grossTotal,
            'generated_at' => now(),
        ]);
    }

    private function makeCompany(): Company
    {
        self::$seq++;

        return Company::create([
            'name'                => 'Company '.self::$seq,
            'tax_number'          => '1234567'.self::$seq.'-2-03',
            'registration_number' => '01-01-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '1111',
            'city'                => 'Budapest',
            'address_line'        => 'Teszt u. 1.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
        ]);
    }

    private function makeUser(bool $superadmin = false): User
    {
        self::$seq++;

        return User::create([
            'name'          => 'User '.self::$seq,
            'email'         => 'user'.self::$seq.'@example.com',
            'password'      => bcrypt('password'),
            'is_superadmin' => $superadmin,
        ]);
    }

    private function asAdmin(Company $company): static
    {
        return $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $company->id])
            ->withHeader('X-Company-Id', (string) $company->id);
    }
}
