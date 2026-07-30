<?php

namespace Tests\Feature;

use App\Models\AuditLog;
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
 * Értékesítő csoportok 3. fázis — superadmin cross-company CRUD.
 *
 * A vizsgált mechanizmus: a `company.cross` middleware
 * (ResolveCrossCompanyContext) a kérés idejére a CÉL cégre állítja a
 * CurrentCompany-t, MÉG a FormRequest feloldása előtt, és onnantól a meglévő,
 * cégre scope-olt CRUD-gépezet fut változatlanul.
 *
 * A tesztek szándékosan úgy vannak felállítva, hogy a superadmin a
 * CÉL cégnek (companyB) NEM tagja — az EnsureCompanyContext ugyanis nem-tag
 * cégre nem engedne kontextust váltani, tehát ez az az eset, amit a
 * hagyományos cég-váltó úton nem lehetne megoldani.
 *
 * Lefedett tételek:
 *   1. Superadmin-kapu: teljes sales_group jogkészletű, de nem-superadmin user
 *      is 403-at kap — MIELŐTT bármi történne (DB változatlan)
 *   2. Create: az auto-stamp a CÉL cég id-jét írja a rekordra
 *   3. Update / delete: a kontextus a bound modell company_id-jából jön
 *   4. Audit: cross_company + target_company_id a payloadban, migráció nélkül
 *   5. Pivot: törléskor nem marad árva sales_group_user sor
 *   6. Validáció-újrafelhasználás: a név-egyediség a CÉL cégre vonatkozik
 *   7. Kontextus-szivárgás: a művelet után a hívó saját cége az aktív
 *   8. Regresszió: a cégen belüli CRUD és annak audit-alakja változatlan
 */
class SalesGroupCrossCompanyCrudTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    /** A hívó saját cége (a superadmin ennek tagja). */
    private Company $companyA;

    /** A CÉL cég — a superadmin NEM tagja. */
    private Company $companyB;

    private User   $superadmin;
    private Module $module;

    private Permission $permView;
    private Permission $permCreate;
    private Permission $permEdit;
    private Permission $permDelete;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();

        $this->companyA = $this->makeCompany('11111111-1-11', 'AAA');
        $this->companyB = $this->makeCompany('22222222-2-22', 'BBB');

        // A superadmin CSAK az A cég tagja — a B cégbe kizárólag a
        // cross-company úton tud írni.
        $this->superadmin = $this->makeUser(superadmin: true);
        $this->superadmin->companies()->attach($this->companyA->id, ['is_default' => true]);
        // A users.default_company_id külön oszlop (a pivot is_default flagje nem
        // írja) — kell a "nem ragad rá a cél cég" teszt érdemi kiindulásához.
        $this->superadmin->update(['default_company_id' => $this->companyA->id]);

        $this->module = $this->makeModule('sales_group');
        $this->enableModule($this->companyA);
        $this->enableModule($this->companyB);

        $this->permView   = Permission::create(['key' => 'sales_group.view',   'module' => 'sales_group', 'description' => 'view',   'is_sensitive' => false]);
        $this->permCreate = Permission::create(['key' => 'sales_group.create', 'module' => 'sales_group', 'description' => 'create', 'is_sensitive' => false]);
        $this->permEdit   = Permission::create(['key' => 'sales_group.edit',   'module' => 'sales_group', 'description' => 'edit',   'is_sensitive' => false]);
        $this->permDelete = Permission::create(['key' => 'sales_group.delete', 'module' => 'sales_group', 'description' => 'delete', 'is_sensitive' => false]);
        Permission::create(['key' => 'sales_group.view_cross_company', 'module' => 'sales_group', 'description' => 'cross-company view', 'is_sensitive' => true]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 1. Superadmin-kapu — fail-closed, a művelet előtt
    // ══════════════════════════════════════════════════════════════════════════

    public function test_non_superadmin_cannot_create_cross_company(): void
    {
        $actor = $this->makeFullyPermissionedUser();

        $this->asUser($actor, $this->companyA)
            ->postJson('/api/admin/sales-groups', [
                'company_id' => $this->companyB->id,
                'name'       => 'Tiltott',
            ])
            ->assertForbidden();

        // A 403-nak a művelet ELŐTT kell jönnie — semmi nem jöhetett létre.
        $this->assertDatabaseMissing('sales_groups', ['name' => 'Tiltott']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'sales_group.create']);
    }

    public function test_non_superadmin_cannot_update_cross_company(): void
    {
        $actor = $this->makeFullyPermissionedUser();
        $group = $this->makeGroup($this->companyB, 'Eredeti');

        $this->asUser($actor, $this->companyA)
            ->putJson("/api/admin/sales-groups/{$group->id}", ['name' => 'Átírt'])
            ->assertForbidden();

        $this->assertDatabaseHas('sales_groups', ['id' => $group->id, 'name' => 'Eredeti']);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'sales_group.update']);
    }

    public function test_non_superadmin_cannot_delete_cross_company(): void
    {
        $actor = $this->makeFullyPermissionedUser();
        $group = $this->makeGroup($this->companyB, 'Megmarad');

        $this->asUser($actor, $this->companyA)
            ->deleteJson("/api/admin/sales-groups/{$group->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('sales_groups', ['id' => $group->id]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'sales_group.delete']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 2. Create — az auto-stamp a CÉL cégre írja a rekordot
    // ══════════════════════════════════════════════════════════════════════════

    public function test_superadmin_creates_group_in_another_company(): void
    {
        $response = $this->asSuperadmin()
            ->postJson('/api/admin/sales-groups', [
                'company_id' => $this->companyB->id,
                'name'       => 'Észak',
            ]);

        $response->assertCreated();
        $response->assertJsonPath('data.company_id', $this->companyB->id);
        // A display_name a CÉL cég prefixét kapja, nem a hívóét.
        $response->assertJsonPath('data.display_name', 'BBB_Észak');

        $this->assertDatabaseHas('sales_groups', [
            'name'       => 'Észak',
            'company_id' => $this->companyB->id,
        ]);
    }

    public function test_create_ignores_company_id_in_mass_assignment(): void
    {
        // A company_id benne van a $fillable-ben, de a StoreSalesGroupRequest
        // szabályai csak a nevet tartalmazzák → a validated() nem hozza át, a
        // cég-hovatartozás kizárólag az auto-stampből származik. Ha ez elromlik,
        // a törzsből becsempészett érték felülírná a middleware döntését.
        $this->asSuperadmin()
            ->postJson('/api/admin/sales-groups', [
                'company_id' => $this->companyB->id,
                'name'       => 'Egy',
            ])
            ->assertCreated();

        $group = SalesGroup::withoutGlobalScope('company')->where('name', 'Egy')->firstOrFail();
        $this->assertSame($this->companyB->id, $group->company_id);
    }

    public function test_create_requires_an_existing_target_company(): void
    {
        // Hiányzó company_id
        $this->asSuperadmin()
            ->postJson('/api/admin/sales-groups', ['name' => 'X'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');

        // Nem létező cég
        $this->asSuperadmin()
            ->postJson('/api/admin/sales-groups', ['company_id' => 999999, 'name' => 'X'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('company_id');

        $this->assertDatabaseMissing('sales_groups', ['name' => 'X']);
    }

    public function test_create_fails_when_target_company_has_no_prefix(): void
    {
        $this->companyB->update(['group_prefix' => null]);

        // A prefix-guard a CÉL cégre fut (a hívó A cégnek van prefixe).
        $this->asSuperadmin()
            ->postJson('/api/admin/sales-groups', [
                'company_id' => $this->companyB->id,
                'name'       => 'Észak',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Előbb állíts be prefixet a cégbeállításoknál.');
    }

    public function test_create_forbidden_when_module_disabled_in_target_company(): void
    {
        // A modul-kapu két helyen érvényesül: az útvonalon ülő
        // module:sales_group a HÍVÓ cégére, a FormRequest can()-je pedig a már
        // átállított kontextuson a CÉL cégére. Itt az utóbbit vizsgáljuk.
        $this->companyB->enabledModules()->updateExistingPivot($this->module->id, ['enabled' => false]);

        $this->asSuperadmin()
            ->postJson('/api/admin/sales-groups', [
                'company_id' => $this->companyB->id,
                'name'       => 'Észak',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('sales_groups', ['name' => 'Észak']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 3. Update / delete — a kontextus a bound modellből
    // ══════════════════════════════════════════════════════════════════════════

    public function test_superadmin_renames_group_in_another_company(): void
    {
        $group = $this->makeGroup($this->companyB, 'Régi');

        $response = $this->asSuperadmin()
            ->putJson("/api/admin/sales-groups/{$group->id}", ['name' => 'Új']);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Új');
        $response->assertJsonPath('data.display_name', 'BBB_Új');

        $this->assertDatabaseHas('sales_groups', [
            'id'         => $group->id,
            'name'       => 'Új',
            'company_id' => $this->companyB->id,
        ]);
    }

    public function test_self_rename_cross_company_does_not_trigger_uniqueness_error(): void
    {
        // Ez a teszt az útvonal-paraméter nevét védi: az UpdateSalesGroupRequest
        // az `sales_group` néven olvassa ki a route-modellt a self-exclude-hoz.
        // Más néven a csoport önmagával ütközne, és hamis 422 jönne.
        $group = $this->makeGroup($this->companyB, 'Ugyanaz');

        $this->asSuperadmin()
            ->putJson("/api/admin/sales-groups/{$group->id}", ['name' => 'Ugyanaz'])
            ->assertOk();
    }

    public function test_superadmin_deletes_group_in_another_company(): void
    {
        $group  = $this->makeGroup($this->companyB, 'Törlendő');
        $member = $this->makeCompanyUser($this->companyB);
        $group->users()->attach($member->id);

        $this->assertDatabaseHas('sales_group_user', [
            'sales_group_id' => $group->id,
            'user_id'        => $member->id,
        ]);

        $this->asSuperadmin()
            ->deleteJson("/api/admin/sales-groups/{$group->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('sales_groups', ['id' => $group->id]);

        // A pivot-sorokat a DB-szintű cascadeOnDelete takarítja — nem maradhat
        // árva sor (l. a `61d24c8` detach-hibacsaládot).
        $this->assertDatabaseMissing('sales_group_user', ['sales_group_id' => $group->id]);
        // A felhasználó maga megmarad, csak a tagsága szűnt meg.
        $this->assertDatabaseHas('users', ['id' => $member->id]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 4. Audit — cross_company jelölők a JSON payloadban
    // ══════════════════════════════════════════════════════════════════════════

    public function test_cross_company_create_audit_records_markers(): void
    {
        $this->asSuperadmin()
            ->postJson('/api/admin/sales-groups', [
                'company_id' => $this->companyB->id,
                'name'       => 'Észak',
            ])
            ->assertCreated();

        $log = AuditLog::where('action', 'sales_group.create')->firstOrFail();

        // Az audit-sor company_id oszlopa is a CÉL cég.
        $this->assertSame($this->companyB->id, $log->company_id);
        $this->assertSame($this->superadmin->id, $log->user_id);
        $this->assertTrue($log->new_values['cross_company']);
        $this->assertSame($this->companyB->id, $log->new_values['target_company_id']);
    }

    public function test_cross_company_update_audit_records_markers(): void
    {
        $group = $this->makeGroup($this->companyB, 'Régi');

        $this->asSuperadmin()
            ->putJson("/api/admin/sales-groups/{$group->id}", ['name' => 'Új'])
            ->assertOk();

        $log = AuditLog::where('action', 'sales_group.update')->firstOrFail();

        $this->assertSame($this->companyB->id, $log->company_id);
        $this->assertTrue($log->new_values['cross_company']);
        $this->assertSame($this->companyB->id, $log->new_values['target_company_id']);
        // A jelölők mindkét oldalon ott vannak, hogy kiessenek a diffből.
        $this->assertTrue($log->old_values['cross_company']);
        $this->assertSame('Régi', $log->old_values['name']);
        $this->assertSame('Új', $log->new_values['name']);
    }

    public function test_cross_company_noop_update_writes_no_audit_row(): void
    {
        // A jelölők mindkét payload-félen szerepelnek, ezért kiejtik egymást a
        // logChange() diffjében: tényleges változás nélkül nincs audit-sor.
        $group = $this->makeGroup($this->companyB, 'Ugyanaz');

        $this->asSuperadmin()
            ->putJson("/api/admin/sales-groups/{$group->id}", ['name' => 'Ugyanaz'])
            ->assertOk();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'sales_group.update']);
    }

    public function test_cross_company_delete_audit_records_markers(): void
    {
        $group = $this->makeGroup($this->companyB, 'Törlendő');

        $this->asSuperadmin()
            ->deleteJson("/api/admin/sales-groups/{$group->id}")
            ->assertNoContent();

        $log = AuditLog::where('action', 'sales_group.delete')->firstOrFail();

        $this->assertSame($this->companyB->id, $log->company_id);
        $this->assertTrue($log->old_values['cross_company']);
        $this->assertSame($this->companyB->id, $log->old_values['target_company_id']);
        $this->assertSame('Törlendő', $log->old_values['name']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 6. Validáció-újrafelhasználás — a név-egyediség a CÉL cégre vonatkozik
    // ══════════════════════════════════════════════════════════════════════════

    public function test_name_uniqueness_is_evaluated_against_the_target_company(): void
    {
        // A hívó saját cégében (A) létezik 'Észak' — ez NEM ütközhet a B cégbeli
        // létrehozással. Ha a validáció a hívó cégére futna, itt hamis 422 jönne.
        $this->makeGroup($this->companyA, 'Észak');

        $this->asSuperadmin()
            ->postJson('/api/admin/sales-groups', [
                'company_id' => $this->companyB->id,
                'name'       => 'Észak',
            ])
            ->assertCreated();

        // Ugyanaz a név MÉGEGYSZER a B cégben → most már ütközik (a szabály
        // tehát tényleg a cél cégen fut, nem egyszerűen ki van kapcsolva).
        $this->asSuperadmin()
            ->postJson('/api/admin/sales-groups', [
                'company_id' => $this->companyB->id,
                'name'       => 'észak',
            ])
            ->assertUnprocessable();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 7. A kérés-szintű kontextus nem szivárog tovább
    // ══════════════════════════════════════════════════════════════════════════

    public function test_cross_company_context_does_not_leak_to_later_requests(): void
    {
        $this->makeGroup($this->companyA, 'A-csoport');
        $group = $this->makeGroup($this->companyB, 'B-csoport');

        $this->asSuperadmin()
            ->putJson("/api/admin/sales-groups/{$group->id}", ['name' => 'B-átnevezve'])
            ->assertOk();

        // Közvetlen bizonyíték: a middleware visszaállította a hívó saját cégét.
        $this->assertSame($this->companyA->id, app(CurrentCompany::class)->id());

        // Következmény: a rákövetkező kérés a normál, saját cégre scope-olt
        // listát adja — nem ragadt rá a cél cég.
        $response = $this->asSuperadmin()->getJson('/api/sales-groups');

        $response->assertOk();
        $names = array_column($response->json('data'), 'name');
        $this->assertSame(['A-csoport'], $names);
    }

    public function test_cross_company_operation_does_not_change_the_persisted_active_company(): void
    {
        $group = $this->makeGroup($this->companyB, 'B-csoport');

        $this->asSuperadmin()
            ->deleteJson("/api/admin/sales-groups/{$group->id}")
            ->assertNoContent()
            // A session aktív cége a hívóé marad (nem a cél cég).
            ->assertSessionHas('current_company_id', $this->companyA->id);

        // A perzisztens alapértelmezett cég sem változott.
        $this->assertSame(
            $this->companyA->id,
            $this->superadmin->fresh()->default_company_id
        );
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 8. Regresszió — a cégen belüli CRUD változatlan
    // ══════════════════════════════════════════════════════════════════════════

    public function test_own_company_crud_still_works_after_the_refactor(): void
    {
        // Create
        $created = $this->asSuperadmin()->postJson('/api/sales-groups', ['name' => 'Saját']);
        $created->assertCreated();
        $created->assertJsonPath('data.display_name', 'AAA_Saját');
        $groupId = $created->json('data.id');

        // Update
        $this->asSuperadmin()
            ->putJson("/api/sales-groups/{$groupId}", ['name' => 'Saját átnevezve'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Saját átnevezve');

        // Delete
        $this->asSuperadmin()
            ->deleteJson("/api/sales-groups/{$groupId}")
            ->assertNoContent();

        $this->assertDatabaseMissing('sales_groups', ['id' => $groupId]);
    }

    public function test_own_company_audit_payload_has_no_cross_company_markers(): void
    {
        // A közös concern csak akkor tesz jelölőket a payloadba, ha kap
        // $auditContext-et — a cégen belüli út audit-alakja tehát változatlan.
        $this->asSuperadmin()
            ->postJson('/api/sales-groups', ['name' => 'Saját'])
            ->assertCreated();

        $log = AuditLog::where('action', 'sales_group.create')->firstOrFail();

        $this->assertSame($this->companyA->id, $log->company_id);
        $this->assertArrayNotHasKey('cross_company', $log->new_values);
        $this->assertArrayNotHasKey('target_company_id', $log->new_values);
        $this->assertNull($log->old_values);
    }

    public function test_update_ignores_company_id_in_mass_assignment(): void
    {
        // A create-teszt párja az UPDATE útra. A company_id benne van a
        // $fillable-ben, ezért az egyetlen védelem az, hogy az
        // UpdateSalesGroupRequest szabályai nem tartalmazzák → a validated()
        // nem hozza át. Ha ez elromlik, egy sima szerkesztéssel bárki ÁT TUDNÁ
        // TOLNI a saját csoportját egy másik cégbe (tenant-szökés).
        $actor = $this->makeFullyPermissionedUser();
        $group = $this->makeGroup($this->companyA, 'Saját');

        $this->asUser($actor, $this->companyA)
            ->putJson("/api/sales-groups/{$group->id}", [
                'name'       => 'Átnevezve',
                'company_id' => $this->companyB->id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('sales_groups', [
            'id'         => $group->id,
            'name'       => 'Átnevezve',
            'company_id' => $this->companyA->id,
        ]);
    }

    public function test_own_company_group_is_still_protected_from_foreign_context(): void
    {
        // A sima (nem cross-company) útvonalon az assertBelongsToCurrentCompany
        // őre változatlanul él: A cég kontextusából B csoportja nem látszik.
        $group = $this->makeGroup($this->companyB, 'Idegen');

        $this->asSuperadmin()
            ->putJson("/api/sales-groups/{$group->id}", ['name' => 'Hekk'])
            ->assertNotFound();

        $this->assertDatabaseHas('sales_groups', ['id' => $group->id, 'name' => 'Idegen']);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Helperek
    // ══════════════════════════════════════════════════════════════════════════

    private function makeCompany(string $taxNumber, string $prefix): Company
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
            'group_prefix'        => $prefix,
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

    private function makeCompanyUser(Company $company): User
    {
        $user = $this->makeUser();
        $user->companies()->attach($company->id, ['is_default' => true]);

        return $user;
    }

    /**
     * Az A cég felhasználója a TELJES sales_group jogkészlettel — de nem
     * superadmin. A superadmin-kapu tesztjeinek épp ez az alanya: ha a kapu
     * jogosultság-alapú lenne, ez a user átjutna rajta.
     */
    private function makeFullyPermissionedUser(): User
    {
        $user = $this->makeCompanyUser($this->companyA);

        app(CurrentCompany::class)->set($this->companyA->id);
        $group = Group::create(['name' => 'Grant '.++self::$seq]);
        $group->users()->attach($user->id);
        $group->permissions()->attach([
            $this->permView->id,
            $this->permCreate->id,
            $this->permEdit->id,
            $this->permDelete->id,
        ]);
        app(CurrentCompany::class)->clear();

        // A PermissionChecker scoped singleton, kulcsonként cache-el.
        app()->forgetScopedInstances();

        return $user;
    }

    private function enableModule(Company $company): void
    {
        $company->enabledModules()->attach($this->module->id, ['enabled' => true]);
    }

    private function asSuperadmin(): static
    {
        return $this->asUser($this->superadmin, $this->companyA);
    }

    private function asUser(User $user, Company $company): static
    {
        return $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $company->id])
            ->withHeader('X-Company-Id', (string) $company->id);
    }
}
