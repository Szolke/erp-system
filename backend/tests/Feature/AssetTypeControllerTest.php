<?php

namespace Tests\Feature;

use App\Models\AssetType;
use App\Models\Company;
use App\Models\Module;
use App\Models\Permission;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Assets module — 4. lépés: AssetType API coverage (v1 scope: index + store).
 *
 * Central concern (kötelező elem #2, step-3 nyitott pont): a store végpontnak
 * EXPLICIT be kell állítania a company_id-t a CurrentCompany-ból — soha nem a
 * kliens inputjából —, különben a típus globálissá válik és átszivárog.
 */
class AssetTypeControllerTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private Company $companyA;
    private Company $companyB;
    private User $superadmin;
    private Module $module;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();

        $this->companyA = $this->makeCompany('DEMO');
        $this->companyB = $this->makeCompany('BETA');

        $this->superadmin = $this->makeUser(superadmin: true);
        $this->superadmin->companies()->attach([
            $this->companyA->id => ['is_default' => true],
            $this->companyB->id => ['is_default' => false],
        ]);

        $this->module = $this->makeModule('assets');
        $this->enableModule($this->companyA);
        $this->enableModule($this->companyB);

        foreach (['asset.view', 'asset.create', 'asset.edit', 'asset.delete'] as $key) {
            Permission::create(['key' => $key, 'module' => 'asset', 'description' => $key, 'is_sensitive' => false]);
        }
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // index — "global OR mine" visibility
    // ══════════════════════════════════════════════════════════════════════════

    public function test_index_returns_global_and_own_company_types(): void
    {
        AssetType::create(['company_id' => null, 'code' => 'TEYA', 'name' => 'Teya POS terminál']);
        AssetType::create(['company_id' => $this->companyA->id, 'code' => 'OWNA', 'name' => 'Own A']);

        $this->asAdmin($this->companyA)
            ->getJson('/api/asset-types')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_does_not_return_other_company_types(): void
    {
        AssetType::create(['company_id' => $this->companyB->id, 'code' => 'OWNB', 'name' => 'Own B']);

        $this->asAdmin($this->companyA)
            ->getJson('/api/asset-types')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Kötelező elem #2 — explicit company_id a store-ban, sosem a kliensből
    // ══════════════════════════════════════════════════════════════════════════

    public function test_store_creates_company_owned_type_with_explicit_company_id(): void
    {
        $response = $this->asAdmin($this->companyA)
            ->postJson('/api/asset-types', ['code' => 'CUSTOM', 'name' => 'Custom típus'])
            ->assertCreated();

        $response->assertJsonPath('data.company_id', $this->companyA->id);
        $response->assertJsonPath('data.is_global', false);
        $this->assertDatabaseHas('asset_types', ['code' => 'CUSTOM', 'company_id' => $this->companyA->id]);
    }

    public function test_store_ignores_client_supplied_company_id(): void
    {
        // Even if a client tries to smuggle a company_id (e.g. null, to force a
        // global row, or another company's id), the controller must override it.
        $this->asAdmin($this->companyA)
            ->postJson('/api/asset-types', [
                'code'       => 'SNEAKY',
                'name'       => 'Sneaky',
                'company_id' => null,
            ])
            ->assertCreated()
            ->assertJsonPath('data.company_id', $this->companyA->id);
    }

    public function test_type_created_by_company_a_not_visible_from_company_b(): void
    {
        $this->asAdmin($this->companyA)
            ->postJson('/api/asset-types', ['code' => 'ACONLY', 'name' => 'A only'])
            ->assertCreated();

        $this->asAdmin($this->companyB)
            ->getJson('/api/asset-types')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_store_returns_422_when_code_duplicate_within_same_company(): void
    {
        AssetType::create(['company_id' => $this->companyA->id, 'code' => 'DUP', 'name' => 'Dup A']);

        $this->asAdmin($this->companyA)
            ->postJson('/api/asset-types', ['code' => 'DUP', 'name' => 'Dup A2'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('code');
    }

    public function test_store_allows_same_code_for_different_company(): void
    {
        AssetType::create(['company_id' => $this->companyB->id, 'code' => 'SHARED', 'name' => 'Shared B']);

        $this->asAdmin($this->companyA)
            ->postJson('/api/asset-types', ['code' => 'SHARED', 'name' => 'Shared A'])
            ->assertCreated();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Jogosítás + modul-gating
    // ══════════════════════════════════════════════════════════════════════════

    public function test_index_returns_403_without_asset_view_permission(): void
    {
        $user = $this->makeUser();
        $this->companyA->users()->attach($user->id);

        $this->asUser($user, $this->companyA)
            ->getJson('/api/asset-types')
            ->assertForbidden();
    }

    public function test_store_returns_403_without_asset_create_permission(): void
    {
        $user = $this->makeUser();
        $this->companyA->users()->attach($user->id);

        $this->asUser($user, $this->companyA)
            ->postJson('/api/asset-types', ['code' => 'X', 'name' => 'X'])
            ->assertForbidden();
    }

    public function test_index_returns_404_when_module_disabled(): void
    {
        $companyC = $this->makeCompany('GAMA');
        $this->superadmin->companies()->attach($companyC->id, ['is_default' => false]);

        $this->asAdmin($companyC)
            ->getJson('/api/asset-types')
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════════

    private function makeCompany(string $groupPrefix): Company
    {
        self::$seq++;
        return Company::withoutGlobalScope('company')->create([
            'name'                => 'Company '.self::$seq,
            'tax_number'          => '1234567'.self::$seq.'-2-03',
            'registration_number' => '01-01-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '1111',
            'city'                => 'Budapest',
            'address_line'        => 'Teszt u. 1.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
            'group_prefix'        => $groupPrefix,
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

    private function makeModule(string $key): Module
    {
        return Module::create([
            'key' => $key, 'name' => ucfirst($key), 'description' => "Test module {$key}",
            'version' => '1.0.0', 'is_core' => false, 'is_available' => true, 'sort_order' => 10,
        ]);
    }

    private function enableModule(Company $company): void
    {
        $company->enabledModules()->attach($this->module->id, ['enabled' => true]);
    }

    private function asAdmin(Company $company): static
    {
        return $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $company->id])
            ->withHeader('X-Company-Id', (string) $company->id);
    }

    private function asUser(User $user, Company $company): static
    {
        return $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $company->id])
            ->withHeader('X-Company-Id', (string) $company->id);
    }
}
