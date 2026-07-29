<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Group;
use App\Models\Module;
use App\Models\Permission;
use App\Models\SalesGroup;
use App\Models\User;
use App\Services\PermissionChecker;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
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
 *
 * 2. fázis (tagság + cross-company nézet):
 *  10. Tagság-szinkron (GET/PUT .../users): attach, detach, teljes leválasztás
 *  11. Scope-leak őr: idegen cég user_id-ja 422
 *  12. Cross-company nézet: superadmin több cég csoportjait látja, normál user 403
 *  13. Jog-paritás: sales_group.view_cross_company a Gate::before és a
 *      PermissionChecker (→ /api/me → frontend can()) útján is ugyanazt adja
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
    private Permission $permCrossCompany;

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
        // Superadmin-only kulcs (PermissionChecker::SUPERADMIN_ONLY_KEYS). A DB-sor
        // azért kell, mert a superadmin úton a checker a Permission tábla kulcsaiból
        // dolgozik; normál usernek a checker akkor sem adja oda, ha csoporthoz kötik.
        $this->permCrossCompany = Permission::create(['key' => 'sales_group.view_cross_company', 'module' => 'sales_group', 'description' => 'cross-company view', 'is_sensitive' => true]);

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
    // 10. Tagság-szinkron (sales_group_user pivot)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_users_endpoint_returns_members(): void
    {
        $this->enableModule($this->companyA);
        $group = $this->makeGroup($this->companyA, 'Észak');
        $member    = $this->makeCompanyUser($this->companyA);
        $nonMember = $this->makeCompanyUser($this->companyA);
        $group->users()->attach($member->id);

        $response = $this->asAdmin($this->companyA)->getJson("/api/sales-groups/{$group->id}/users");

        $response->assertOk();
        $ids = array_column($response->json('data'), 'id');
        $this->assertSame([$member->id], $ids);
        $this->assertNotContains($nonMember->id, $ids);
    }

    public function test_sync_users_attaches_members(): void
    {
        $this->enableModule($this->companyA);
        $group = $this->makeGroup($this->companyA, 'Észak');
        $userOne = $this->makeCompanyUser($this->companyA);
        $userTwo = $this->makeCompanyUser($this->companyA);

        $response = $this->asAdmin($this->companyA)
            ->putJson("/api/sales-groups/{$group->id}/users", ['user_ids' => [$userOne->id, $userTwo->id]]);

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $this->assertDatabaseHas('sales_group_user', ['sales_group_id' => $group->id, 'user_id' => $userOne->id]);
        $this->assertDatabaseHas('sales_group_user', ['sales_group_id' => $group->id, 'user_id' => $userTwo->id]);
    }

    public function test_sync_users_detaches_missing_members(): void
    {
        $this->enableModule($this->companyA);
        $group = $this->makeGroup($this->companyA, 'Észak');
        $stays = $this->makeCompanyUser($this->companyA);
        $goes  = $this->makeCompanyUser($this->companyA);
        $group->users()->attach([$stays->id, $goes->id]);

        $this->asAdmin($this->companyA)
            ->putJson("/api/sales-groups/{$group->id}/users", ['user_ids' => [$stays->id]])
            ->assertOk();

        $this->assertDatabaseHas('sales_group_user', ['sales_group_id' => $group->id, 'user_id' => $stays->id]);
        $this->assertDatabaseMissing('sales_group_user', ['sales_group_id' => $group->id, 'user_id' => $goes->id]);
    }

    public function test_sync_users_with_empty_array_detaches_all(): void
    {
        $this->enableModule($this->companyA);
        $group = $this->makeGroup($this->companyA, 'Észak');
        $user  = $this->makeCompanyUser($this->companyA);
        $group->users()->attach($user->id);

        $this->asAdmin($this->companyA)
            ->putJson("/api/sales-groups/{$group->id}/users", ['user_ids' => []])
            ->assertOk();

        $this->assertDatabaseMissing('sales_group_user', ['sales_group_id' => $group->id]);
    }

    public function test_sync_users_writes_audit_log(): void
    {
        $this->enableModule($this->companyA);
        $group = $this->makeGroup($this->companyA, 'Észak');
        $user  = $this->makeCompanyUser($this->companyA);

        $this->asAdmin($this->companyA)
            ->putJson("/api/sales-groups/{$group->id}/users", ['user_ids' => [$user->id]])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action'        => 'sales_group.members_sync',
            'company_id'    => $this->companyA->id,
            'auditable_id'  => $group->id,
        ]);
    }

    public function test_sync_users_requires_edit_permission(): void
    {
        $this->enableModule($this->companyA);
        $group = $this->makeGroup($this->companyA, 'Észak');
        $member = $this->makeCompanyUser($this->companyA);

        // Csak view jog → a tagság-szinkronnak el kell buknia.
        $actor = $this->makeCompanyUser($this->companyA);
        $this->grantPermissions($actor, $this->companyA, [$this->permView]);

        $this->asUser($actor, $this->companyA)
            ->putJson("/api/sales-groups/{$group->id}/users", ['user_ids' => [$member->id]])
            ->assertForbidden();
    }

    public function test_sync_users_404_for_other_company_group(): void
    {
        $this->enableModule($this->companyA);
        $this->enableModule($this->companyB);
        $groupB = $this->makeGroup($this->companyB, 'B-csoport');
        $userA  = $this->makeCompanyUser($this->companyA);

        // CompanyA kontextusából CompanyB csoportja nem létezik (assertBelongsToCurrentCompany)
        $this->asAdmin($this->companyA)
            ->putJson("/api/sales-groups/{$groupB->id}/users", ['user_ids' => [$userA->id]])
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 11. Scope-leak őr — idegen cég felhasználója nem rendelhető hozzá
    // ══════════════════════════════════════════════════════════════════════════

    public function test_sync_users_rejects_user_from_another_company(): void
    {
        $this->enableModule($this->companyA);
        $this->enableModule($this->companyB);
        $group   = $this->makeGroup($this->companyA, 'Észak');
        $foreign = $this->makeCompanyUser($this->companyB);

        $response = $this->asAdmin($this->companyA)
            ->putJson("/api/sales-groups/{$group->id}/users", ['user_ids' => [$foreign->id]]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('user_ids.0');
        $this->assertDatabaseMissing('sales_group_user', ['sales_group_id' => $group->id, 'user_id' => $foreign->id]);
    }

    public function test_sync_users_rejects_batch_containing_foreign_user(): void
    {
        $this->enableModule($this->companyA);
        $this->enableModule($this->companyB);
        $group   = $this->makeGroup($this->companyA, 'Észak');
        $ownUser = $this->makeCompanyUser($this->companyA);
        $foreign = $this->makeCompanyUser($this->companyB);

        $this->asAdmin($this->companyA)
            ->putJson("/api/sales-groups/{$group->id}/users", ['user_ids' => [$ownUser->id, $foreign->id]])
            ->assertStatus(422);

        // Az egész kérés elbukik — a "jó" user_id sem íródhat be részlegesen.
        $this->assertDatabaseMissing('sales_group_user', ['sales_group_id' => $group->id]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 12. Cross-company nézet (superadmin, csak olvasás)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_cross_company_index_returns_groups_from_all_companies(): void
    {
        $this->enableModule($this->companyA);
        $this->companyA->update(['group_prefix' => 'AA']);
        $this->companyB->update(['group_prefix' => 'BB']);
        $groupA = $this->makeGroup($this->companyA, 'Észak');
        $groupB = $this->makeGroup($this->companyB, 'Dél');
        $memberA = $this->makeCompanyUser($this->companyA);
        $groupA->users()->attach($memberA->id);

        $response = $this->asAdmin($this->companyA)->getJson('/api/admin/sales-groups');

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(2, $data, 'Superadminnak mindkét cég csoportját látnia kell');

        $byId = collect($data)->keyBy('id');
        $this->assertSame('AA_Észak', $byId[$groupA->id]['display_name']);
        // A csoport SAJÁT cégének prefixe, nem az aktuális (A) cégé:
        $this->assertSame('BB_Dél', $byId[$groupB->id]['display_name']);
        $this->assertSame($this->companyB->name, $byId[$groupB->id]['company']['name']);
        $this->assertSame([$memberA->id], array_column($byId[$groupA->id]['users'], 'id'));
        $this->assertSame([], $byId[$groupB->id]['users']);
    }

    public function test_cross_company_index_forbidden_for_non_superadmin(): void
    {
        $this->enableModule($this->companyA);
        $this->makeGroup($this->companyA, 'Észak');

        // Teljes sales_group jogkészlet, de NEM superadmin.
        $user = $this->makeCompanyUser($this->companyA);
        $this->grantPermissions($user, $this->companyA, [
            $this->permView, $this->permCreate, $this->permEdit, $this->permDelete,
        ]);

        $this->asUser($user, $this->companyA)
            ->getJson('/api/admin/sales-groups')
            ->assertForbidden();
    }

    public function test_cross_company_index_forbidden_even_if_permission_granted_to_group(): void
    {
        $this->enableModule($this->companyA);
        $this->makeGroup($this->companyA, 'Észak');

        // A UI-t megkerülve valaki a superadmin-only kulcsot csoporthoz köti —
        // a PermissionChecker akkor sem adhatja meg.
        $user = $this->makeCompanyUser($this->companyA);
        $this->grantPermissions($user, $this->companyA, [$this->permCrossCompany]);

        $this->asUser($user, $this->companyA)
            ->getJson('/api/admin/sales-groups')
            ->assertForbidden();
    }

    public function test_cross_company_index_404_when_module_disabled(): void
    {
        // A modul nincs engedélyezve companyA-ra → a route middleware 404-et ad.
        $this->makeGroup($this->companyA, 'Észak');

        $this->asAdmin($this->companyA)
            ->getJson('/api/admin/sales-groups')
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 13. Jog-paritás: Gate::before ↔ PermissionChecker (/api/me → can())
    // ══════════════════════════════════════════════════════════════════════════

    public function test_superadmin_gets_cross_company_key_on_both_resolution_paths(): void
    {
        $this->enableModule($this->companyA);
        app(CurrentCompany::class)->set($this->companyA->id);

        $keys = app(PermissionChecker::class)
            ->effectivePermissionKeys($this->superadmin, $this->companyA->id);

        // (a) PermissionChecker → /api/me → frontend can()
        $this->assertContains('sales_group.view_cross_company', $keys);
        // (b) Gate::before → backend authorize()
        $this->assertTrue(Gate::forUser($this->superadmin)->allows('sales_group.view_cross_company'));
    }

    public function test_normal_user_never_gets_cross_company_key_on_either_path(): void
    {
        $this->enableModule($this->companyA);

        $user = $this->makeCompanyUser($this->companyA);
        // Szándékosan MEGADJUK a kulcsot csoporton keresztül — nem szabad átjutnia.
        $this->grantPermissions($user, $this->companyA, [$this->permView, $this->permCrossCompany]);

        app(CurrentCompany::class)->set($this->companyA->id);
        $keys = app(PermissionChecker::class)->effectivePermissionKeys($user, $this->companyA->id);

        // (a) A kulcs nem szivárog be a /api/me válaszba…
        $this->assertNotContains('sales_group.view_cross_company', $keys);
        // …de a normálisan kapott jog megmarad (nem "mindent kivágtunk" hiba).
        $this->assertContains('sales_group.view', $keys);
        // (b) …és a backend Gate sem engedi át.
        $this->assertFalse(Gate::forUser($user)->allows('sales_group.view_cross_company'));
        $this->assertTrue(Gate::forUser($user)->allows('sales_group.view'));
    }

    /*
     * A /api/me két oldalát szándékosan KÉT teszt fedi le, nem egy.
     * A Sanctum stateful stackjében ott ül az AuthenticateSession middleware
     * (config/sanctum.php), ami a sessionbe mentett password_hash_web-et veti
     * össze a bejelentkezett userrel. Egy teszten belül két különböző userrel
     * kérve a session átöröklődik az első kérésből, a hash nem egyezik, és a
     * middleware kilépteti a másodikat → 401. Ez teszt-harness sajátosság, nem
     * alkalmazás-hiba, de a tesztet szét kell rá bontani.
     */

    public function test_me_endpoint_exposes_cross_company_key_to_superadmin(): void
    {
        $this->enableModule($this->companyA);

        $response = $this->asAdmin($this->companyA)->getJson('/api/me');

        $response->assertOk();
        $this->assertContains('sales_group.view_cross_company', $response->json('permissions'));
    }

    public function test_me_endpoint_hides_cross_company_key_from_normal_user(): void
    {
        $this->enableModule($this->companyA);

        $user = $this->makeCompanyUser($this->companyA);
        $this->grantPermissions($user, $this->companyA, [$this->permView]);

        $response = $this->asUser($user, $this->companyA)->getJson('/api/me');

        $response->assertOk();
        $this->assertNotContains('sales_group.view_cross_company', $response->json('permissions'));
        // Kontroll: a normálisan megadott jog megvan — nem "mindent kivágtunk" hiba.
        $this->assertContains('sales_group.view', $response->json('permissions'));
    }

    public function test_permission_catalog_hides_superadmin_only_keys(): void
    {
        $this->enableModule($this->companyA);

        $keys = array_column(
            $this->asAdmin($this->companyA)->getJson('/api/permissions')->json('data'),
            'key'
        );

        // A csoport-szerkesztő nem kínálhat olyan checkboxot, ami néma no-op lenne.
        $this->assertNotContains('sales_group.view_cross_company', $keys);
        $this->assertContains('sales_group.view', $keys);
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

    /** Új felhasználó, a megadott cég tagjaként (company_user pivot). */
    private function makeCompanyUser(Company $company): User
    {
        $user = $this->makeUser();
        $user->companies()->attach($company->id, ['is_default' => true]);

        return $user;
    }

    /**
     * Jogosultságok megadása egy usernek cég-scope-olt csoporton keresztül.
     * A Group a BelongsToCompany scope-ot használja, ezért a létrehozás
     * idejére be kell állítani a cég-kontextust.
     *
     * @param  array<Permission>  $permissions
     */
    private function grantPermissions(User $user, Company $company, array $permissions): void
    {
        app(CurrentCompany::class)->set($company->id);
        $group = Group::create(['name' => 'Grant '.++self::$seq]);
        $group->users()->attach($user->id);
        $group->permissions()->attach(array_map(fn (Permission $p) => $p->id, $permissions));
        app(CurrentCompany::class)->clear();

        // A PermissionChecker scoped singleton, kulcsonként cache-el — a frissen
        // adott jog különben nem látszana a következő lekérdezésnél.
        app()->forgetScopedInstances();
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
