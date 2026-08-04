<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\CustomFieldDefinition;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/custom-fields — whitelistelt rendezés (`sort_by` / `sort_dir`).
 *
 * A `required`/`active` az `is_required`/`is_active` oszlopokra rendez —
 * l. CustomFieldDefinitionController SORTABLE_COLUMNS. Az authorize()
 * `company.manage`-et vár, amit a superadmin Gate::before-ja fed.
 */
class CustomFieldDefinitionSortTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    public function test_without_sort_params_the_previous_sort_order_default_is_kept(): void
    {
        $this->makeField('zeta_key', sortOrder: 2);
        $this->makeField('alfa_key', sortOrder: 1);

        $keys = $this->keysAs([]);

        $this->assertSame(['alfa_key', 'zeta_key'], $keys, 'Alapértelmezés: sort_order szerint növekvő');
    }

    public function test_unknown_sort_column_silently_falls_back_to_the_default_order(): void
    {
        $this->makeField('zeta_key', sortOrder: 2);
        $this->makeField('alfa_key', sortOrder: 1);

        $keys = $this->keysAs(['sort_by' => 'nincs_ilyen', 'sort_dir' => 'desc']);

        $this->assertSame(['alfa_key', 'zeta_key'], $keys);
    }

    public function test_sorting_by_key_descending(): void
    {
        $this->makeField('alfa_key');
        $this->makeField('zeta_key');

        $keys = array_map(
            fn ($r) => $r['key'],
            $this->fieldsAs(['sort_by' => 'key', 'sort_dir' => 'desc'])
        );

        $this->assertSame(['zeta_key', 'alfa_key'], $keys);
    }

    /** `required` a is_required oszlopra rendez. */
    public function test_sorting_by_required(): void
    {
        $this->makeField('opt_key', required: false);
        $this->makeField('req_key', required: true);

        $rows = $this->fieldsAs(['sort_by' => 'required', 'sort_dir' => 'asc']);

        $this->assertFalse($rows[0]['is_required']);
        $this->assertTrue($rows[1]['is_required']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /** @return string[] */
    private function keysAs(array $query): array
    {
        return array_map(fn ($row) => $row['key'], $this->fieldsAs($query));
    }

    private function fieldsAs(array $query): array
    {
        return $this->asAdmin($this->company)
            ->getJson('/api/custom-fields?'.http_build_query($query))
            ->assertOk()
            ->json('data');
    }

    private function makeField(string $key, int $sortOrder = 0, bool $required = false): CustomFieldDefinition
    {
        return CustomFieldDefinition::create([
            'company_id' => $this->company->id,
            'entity_type' => 'partner',
            'key' => $key,
            'label' => $key,
            'type' => 'text',
            'is_required' => $required,
            'sort_order' => $sortOrder,
            'is_active' => true,
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
