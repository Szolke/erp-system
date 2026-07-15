<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Group;
use App\Models\Module;
use App\Models\Permission;
use App\Models\SalesGroup;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sales group module — backend coverage:
 *   1. CRUD happy paths
 *   2. Authorization (sales_group.* permissions required)
 *   3. Tenant isolation (A's groups invisible from B context)
 *   4. Module gating (404 when module disabled)
 *   5. Prefix guard — both directions (store without prefix, clear with groups)
 *   6. Prefix change while groups exist (allowed — only null is blocked)
 *   7. Case-insensitive name uniqueness
 *   8. Global prefix unique → human-readable 422 (not a raw DB error)
 *   9. display_name source: uses the group's OWN company prefix (not CurrentCompany)
 */
class SalesGroupTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private Company $companyA;
    private Company $companyB;
    private User    $superadmin;
    private Module  $module;

    // Permissions used across tests
    private Permission $permView;
    private Permission $permCreate;
    private Permission $permEdit;
    private Permission $permDelete;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();

        $this->companyA   = $this->makeCompany('11111111-1-11');
        $this->companyB   = $this->makeCompany('22222222-2-22');
        $this->superadmin = $this->makeUser(superadmin: true);
        $this->superadmin->companies()->attach([
            $this->companyA->id => ['is_default' => true],
            $this->companyB->id => ['is_default' => false],
        ]);

        $this->module = $this->makeModule('sales_group');

        $this->permView   = Permission::create(['key' => 'sales_group.view',   'module' => 'sales_group', 'description' => 'view',   'is_sensitive' => false]);
        $this->permCreate = Permission::create(['key' => 'sales_group.create', 'module' => 'sales_group', 'description' => 'create', 'is_sensitive' => false]);
        $this->permEdit   = Permission::create(['key' => 'sales_group.edit',   'module' => 'sales_group', 'description' => 'edit',   'is_sensitive' => false]);
        $this->permDelete = Permission::create(['key' => 'sales_group.delete', 'module' => 'sales_group', 'description' => 'delete', 'is_sensitive' => false]);

        // company.manage szükséges a PUT /api/company útvonalhoz (UpdateCompanyRequest::authorize).
        // Ungated permission (nincs descriptor-ban), de a DB-ben léteznie kell, hogy a
        // PermissionChecker superadmin úton belerakja az effectivePermissionKeys-be.
        Permission::create(['key' => 'company.manage', 'module' => 'company', 'description' => 'Cégadatok szerkesztése', 'is_sensitive' => true]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 1. CRUD happy paths
    // ══════════════════════════════════════════════════════════════════════════

    public function test_index_returns_own_company_groups(): void
    {
        $this->enableModule($this->companyA);
        $this->companyA->update(['group_prefix' => 'DEMO']);
        $this->makeGroup($this->companyA, 'Észak');
        $this->makeGroup($this->companyA, 'Dél');
        $this->makeGroup($this->companyB, 'Egyéb'); // ne látsszon

        $response = $this->asAdmin($this->companyA)->getJson('/api/sales-groups');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_store_creates_sales_group_with_201(): void
    {
        $this->enableModule($this->companyA);
        $this->companyA->update(['group_prefix' => 'TST']);

        $response = $this->asAdmin($this->companyA)->postJson('/api/sales-groups', ['name' => 'Észak']);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'Észak');
        $response->assertJsonPath('data.display_name', 'TST_Észak');
        $this->assertDatabaseHas('sales_groups', ['name' => 'Észak', 'company_id' => $this->companyA->id]);
    }

    public function test_show_returns_single_group(): void
    {
        $this->enableModule($this->companyA);
        $this->companyA->update(['group_prefix' => 'TST']);
        $group = $this->makeGroup($this->companyA, 'Közép');

        $response = $this->asAdmin($this->companyA)->getJson("/api/sales-groups/{$group->id}");

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Közép');
        $response->assertJsonPath('data.display_name', 'TST_Közép');
    }

    public function test_update_renames_group(): void
    {
        $this->enableModule($this->companyA);
        $this->companyA->update(['group_prefix' => 'TST']);
        $group = $this->makeGroup($this->companyA, 'Régi');

        $response = $this->asAdmin($this->companyA)->putJson("/api/sales-groups/{$group->id}", ['name' => 'Új']);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Új');
        $this->assertDatabaseHas('sales_groups', ['id' => $group->id, 'name' => 'Új']);
    }

    public function test_destroy_deletes_group(): void
    {
        $this->enableModule($this->companyA);
        $this->companyA->update(['group_prefix' => 'TST']);
        $group = $this->makeGroup($this->companyA, 'Törlendő');

        $response = $this->asAdmin($this->companyA)->deleteJson("/api/sales-groups/{$group->id}");

        $response->assertNoContent();
        $this->assertDatabaseMissing('sales_groups', ['id' => $group->id]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 2. Authorization
    // ══════════════════════════════════════════════════════════════════════════

    public function test_index_returns_403_without_view_permission(): void
    {
        $this->enableModule($this->companyA);
        $user = $this->makeUser();
        $user->companies()->attach($this->companyA->id, ['is_default' => true]);
        // No group → no sales_group.view permission

        $this->asUser($user, $this->companyA)->getJson('/api/sales-groups')->assertForbidden();
    }

    public function test_store_returns_403_without_create_permission(): void
    {
        $this->enableModule($this->companyA);
        $this->companyA->update(['group_prefix' => 'TST']);
        $user = $this->makeUser();
        $user->companies()->attach($this->companyA->id, ['is_default' => true]);

        $this->asUser($user, $this->companyA)
            ->postJson('/api/sales-groups', ['name' => 'Test'])
            ->assertForbidden();
    }

    public function test_destroy_returns_403_without_delete_permission(): void
    {
        $this->enableModule($this->companyA);
        $this->companyA->update(['group_prefix' => 'TST']);
        $group = $this->makeGroup($this->companyA, 'Törlendő');

        $user = $this->makeUser();
        $user->companies()->attach($this->companyA->id, ['is_default' => true]);

        // Only view permission → delete must fail
        app(CurrentCompany::class)->set($this->companyA->id);
        $viewGroup = Group::create(['name' => 'Viewers']);
        $viewGroup->users()->attach($user->id);
        $viewGroup->permissions()->attach($this->permView->id);
        app(CurrentCompany::class)->clear();

        $this->asUser($user, $this->companyA)
            ->deleteJson("/api/sales-groups/{$group->id}")
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 3. Tenant isolation — a LEGFONTOSABB
    // ══════════════════════════════════════════════════════════════════════════

    public function test_company_b_cannot_see_company_a_groups_in_list(): void
    {
        $this->enableModule($this->companyA);
        $this->enableModule($this->companyB);
        $this->companyA->update(['group_prefix' => 'AAA']);
        $this->companyB->update(['group_prefix' => 'BBB']);
        $this->makeGroup($this->companyA, 'A-csoport');

        $response = $this->asAdmin($this->companyB)->getJson('/api/sales-groups');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    public function test_company_b_cannot_show_company_a_group_by_id(): void
    {
        $this->enableModule($this->companyA);
        $this->enableModule($this->companyB);
        $this->companyA->update(['group_prefix' => 'AAA']);
        $group = $this->makeGroup($this->companyA, 'Titkos');

        $this->asAdmin($this->companyB)
            ->getJson("/api/sales-groups/{$group->id}")
            ->assertNotFound();
    }

    public function test_company_b_cannot_update_company_a_group(): void
    {
        $this->enableModule($this->companyA);
        $this->enableModule($this->companyB);
        $this->companyA->update(['group_prefix' => 'AAA']);
        $this->companyB->update(['group_prefix' => 'BBB']);
        $group = $this->makeGroup($this->companyA, 'Eredeti');

        $this->asAdmin($this->companyB)
            ->putJson("/api/sales-groups/{$group->id}", ['name' => 'Módosított'])
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 4. Module gating
    // ══════════════════════════════════════════════════════════════════════════

    public function test_index_returns_404_when_module_disabled(): void
    {
        // Module létezik DB-ben, de companyA-hoz nincs csatolva → EnsureModuleEnabled → 404
        $this->asAdmin($this->companyA)->getJson('/api/sales-groups')->assertNotFound();
    }

    public function test_store_returns_404_when_module_disabled(): void
    {
        $this->asAdmin($this->companyA)
            ->postJson('/api/sales-groups', ['name' => 'Test'])
            ->assertNotFound();
    }

    public function test_sales_group_permissions_absent_from_effective_keys_when_module_off(): void
    {
        // Modul ki van kapcsolva → a 4 sales_group.* kulcs nem szerepel az effektív jogokban
        app(CurrentCompany::class)->set($this->companyA->id);
        $keys = app(\App\Services\PermissionChecker::class)
            ->effectivePermissionKeys($this->superadmin, $this->companyA->id);
        app(CurrentCompany::class)->clear();

        $this->assertNotContains('sales_group.view',   $keys);
        $this->assertNotContains('sales_group.create', $keys);
        $this->assertNotContains('sales_group.edit',   $keys);
        $this->assertNotContains('sales_group.delete', $keys);
    }

    public function test_sales_group_permissions_present_when_module_on(): void
    {
        $this->enableModule($this->companyA);

        app(CurrentCompany::class)->set($this->companyA->id);
        $keys = app(\App\Services\PermissionChecker::class)
            ->effectivePermissionKeys($this->superadmin, $this->companyA->id);
        app(CurrentCompany::class)->clear();

        $this->assertContains('sales_group.view',   $keys);
        $this->assertContains('sales_group.create', $keys);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 5–6. Prefix guard — kétirányú
    // ══════════════════════════════════════════════════════════════════════════

    public function test_store_returns_422_when_company_has_no_prefix(): void
    {
        $this->enableModule($this->companyA);
        // companyA-nak nincs group_prefix-e

        $this->asAdmin($this->companyA)
            ->postJson('/api/sales-groups', ['name' => 'Csoport'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Előbb állíts be prefixet a cégbeállításoknál.');
    }

    public function test_cannot_clear_prefix_while_sales_groups_exist(): void
    {
        $this->companyA->update(['group_prefix' => 'TST']);
        $this->makeGroup($this->companyA, 'Csoport');

        $response = $this->asAdmin($this->companyA)->putJson('/api/company', $this->companyPayload($this->companyA, [
            'group_prefix' => null,
        ]));

        $response->assertUnprocessable();
        $this->assertStringContainsString('töröld', $response->json('message'));
    }

    public function test_cannot_clear_prefix_via_empty_string_while_sales_groups_exist(): void
    {
        // A frontend jellemzően "" -t küld, nem null-t, ha a felhasználó kiüríti a mezőt.
        // prepareForValidation konvertálja null-ra → ugyanaz a guard lép be.
        $this->companyA->update(['group_prefix' => 'TST']);
        $this->makeGroup($this->companyA, 'Csoport');

        $response = $this->asAdmin($this->companyA)->putJson('/api/company', $this->companyPayload($this->companyA, [
            'group_prefix' => '',
        ]));

        $response->assertUnprocessable();
        $this->assertStringContainsString('töröld', $response->json('message'));
    }

    public function test_can_change_prefix_when_groups_exist(): void
    {
        $this->companyA->update(['group_prefix' => 'OLD']);
        $this->makeGroup($this->companyA, 'Csoport');

        // Prefix VÁLTOZTATÁS (nem törlés) → OK
        $response = $this->asAdmin($this->companyA)->putJson('/api/company', $this->companyPayload($this->companyA, [
            'group_prefix' => 'NEW',
        ]));

        $response->assertOk();
        $this->assertDatabaseHas('companies', ['id' => $this->companyA->id, 'group_prefix' => 'NEW']);
    }

    public function test_can_clear_prefix_when_no_groups_exist(): void
    {
        $this->companyA->update(['group_prefix' => 'TST']);

        $response = $this->asAdmin($this->companyA)->putJson('/api/company', $this->companyPayload($this->companyA, [
            'group_prefix' => null,
        ]));

        $response->assertOk();
        $this->assertDatabaseHas('companies', ['id' => $this->companyA->id, 'group_prefix' => null]);
    }

    public function test_prefix_uppercased_by_prepare_for_validation(): void
    {
        $response = $this->asAdmin($this->companyA)->putJson('/api/company', $this->companyPayload($this->companyA, [
            'group_prefix' => 'demo',
        ]));

        $response->assertOk();
        $this->assertDatabaseHas('companies', ['id' => $this->companyA->id, 'group_prefix' => 'DEMO']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 7. Case-insensitive csoportnév-unique
    // ══════════════════════════════════════════════════════════════════════════

    public function test_duplicate_name_case_insensitive_returns_422(): void
    {
        $this->enableModule($this->companyA);
        $this->companyA->update(['group_prefix' => 'TST']);
        $this->makeGroup($this->companyA, 'Csoport');

        $this->asAdmin($this->companyA)
            ->postJson('/api/sales-groups', ['name' => 'csoport'])
            ->assertUnprocessable();
    }

    public function test_same_name_allowed_in_different_companies(): void
    {
        $this->enableModule($this->companyA);
        $this->enableModule($this->companyB);
        $this->companyA->update(['group_prefix' => 'AAA']);
        $this->companyB->update(['group_prefix' => 'BBB']);
        $this->makeGroup($this->companyA, 'Észak');

        // Ugyanaz a név CompanyB-ben → szabad
        $this->asAdmin($this->companyB)
            ->postJson('/api/sales-groups', ['name' => 'Észak'])
            ->assertCreated();
    }

    public function test_update_own_name_does_not_trigger_unique_error(): void
    {
        $this->enableModule($this->companyA);
        $this->companyA->update(['group_prefix' => 'TST']);
        $group = $this->makeGroup($this->companyA, 'Csoport');

        // Saját nevére mentés → nem ütközik önmagával
        $this->asAdmin($this->companyA)
            ->putJson("/api/sales-groups/{$group->id}", ['name' => 'Csoport'])
            ->assertOk();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 8. Globális prefix-unique → érthető 422 (nem nyers DB-hiba)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_duplicate_prefix_returns_422_with_human_readable_message(): void
    {
        // CompanyB-nek már van 'DEMO' prefixe (közvetlen modell-írással, FormRequest megkerülésével)
        $this->companyB->update(['group_prefix' => 'DEMO']);

        // CompanyA próbálja beállítani ugyanazt a prefixet API-on keresztül
        $response = $this->asAdmin($this->companyA)->putJson('/api/company', $this->companyPayload($this->companyA, [
            'group_prefix' => 'DEMO',
        ]));

        // A Rule::unique validáció elkapja (normál eset), vagy a QueryException catch (versenyhelyzet)
        // Mindkét úton 422-t és érthető üzenetet várunk
        $response->assertUnprocessable();
        // Rule::unique error → errors.group_prefix tömbben; üzenet a 'message' mezőben is
        $this->assertTrue(
            $response->json('errors.group_prefix') !== null || str_contains((string) $response->json('message'), 'prefix'),
            'Expected a prefix-related 422 error'
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 9. display_name forrás — a saját cég prefixét használja, nem CurrentCompany-t
    // ══════════════════════════════════════════════════════════════════════════

    public function test_display_name_uses_own_company_prefix_not_current_company(): void
    {
        $this->enableModule($this->companyA);
        $this->enableModule($this->companyB);
        $this->companyA->update(['group_prefix' => 'AA']);
        $this->companyB->update(['group_prefix' => 'BB']);

        $this->makeGroup($this->companyA, 'North');
        $this->makeGroup($this->companyB, 'South');

        // CompanyA kontextusából kérjük a saját csoportját
        $responseA = $this->asAdmin($this->companyA)->getJson('/api/sales-groups');
        $responseA->assertOk();
        $namesA = array_column($responseA->json('data'), 'display_name');
        $this->assertContains('AA_North', $namesA, 'CompanyA csoportjának AA prefixet kell kapnia');

        // CompanyB kontextusából kérjük a saját csoportját
        $responseB = $this->asAdmin($this->companyB)->getJson('/api/sales-groups');
        $responseB->assertOk();
        $namesB = array_column($responseB->json('data'), 'display_name');
        $this->assertContains('BB_South', $namesB, 'CompanyB csoportjának BB prefixet kell kapnia');
        $this->assertNotContains('AA_South', $namesB, 'CompanyB csoportja NEM kaphatja AA prefixet');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Segédmetódusok
    // ══════════════════════════════════════════════════════════════════════════

    private function makeCompany(string $taxNumber): Company
    {
        return Company::create([
            'name'                => 'Cég '.++self::$seq,
            'tax_number'          => $taxNumber,
            'registration_number' => '01-09-000001',
            'postal_code'         => '1000',
            'city'                => 'Budapest',
            'address_line'        => 'Teszt utca 1.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
        ]);
    }

    private function makeUser(bool $superadmin = false): User
    {
        return User::create([
            'name'          => 'User '.++self::$seq,
            'email'         => 'user'.self::$seq.'@test.dev',
            'password'      => bcrypt('password'),
            'is_superadmin' => $superadmin,
        ]);
    }

    private function makeModule(string $key): Module
    {
        return Module::create([
            'key'          => $key,
            'name'         => ucfirst(str_replace('_', ' ', $key)),
            'description'  => "Test module {$key}",
            'version'      => '1.0.0',
            'is_core'      => false,
            'is_available' => true,
            'sort_order'   => 99,
        ]);
    }

    private function makeGroup(Company $company, string $name): SalesGroup
    {
        return SalesGroup::withoutGlobalScope('company')->create([
            'company_id' => $company->id,
            'name'       => $name,
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

    /** Teljes érvényes PUT /api/company payload a cég aktuális adataival + opcionális override-ok. */
    private function companyPayload(Company $company, array $overrides = []): array
    {
        $company->refresh();

        return array_merge([
            'name'                => $company->name,
            'tax_number'          => $company->tax_number,
            'registration_number' => $company->registration_number,
            'postal_code'         => $company->postal_code,
            'city'                => $company->city,
            'address_line'        => $company->address_line,
            'country_code'        => $company->country_code,
            'base_currency'       => $company->base_currency,
            'is_active'           => true,
        ], $overrides);
    }
}
