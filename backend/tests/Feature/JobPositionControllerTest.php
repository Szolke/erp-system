<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Group;
use App\Models\JobPosition;
use App\Models\Permission;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Munkakör (job_position) — 2. lépés: jogkulcs + jog-feloldás + API coverage.
 *
 * A job_position.manage egyetlen kulcs védi az összes írás-műveletet
 * (document_series.manage mintája), NEM modul-gated. A listázás nem igényel
 * jogot (vat-rates/payment-methods mintája — a user-selector bárkinek működik).
 * A globális (company_id IS NULL) sorok írása/törlése superadmin-only — a
 * job_position.manage jog egy céges csoporton át is megszerezhető, de az
 * nem jogosít fel minden céget érintő sorra.
 */
class JobPositionControllerTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private Company $companyA;
    private Company $companyB;
    private User $superadmin;
    private Permission $permManage;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();

        $this->companyA = $this->makeCompany();
        $this->companyB = $this->makeCompany();

        $this->superadmin = $this->makeUser(superadmin: true);
        $this->superadmin->companies()->attach([
            $this->companyA->id => ['is_default' => true],
            $this->companyB->id => ['is_default' => false],
        ]);

        $this->permManage = Permission::create([
            'key'          => 'job_position.manage',
            'module'       => 'job_position',
            'description'  => 'manage',
            'is_sensitive' => false,
        ]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // index — visibility scope, nem igényel job_position.manage jogot
    // ══════════════════════════════════════════════════════════════════════════

    public function test_index_returns_global_and_own_company_active_positions(): void
    {
        JobPosition::create(['company_id' => null, 'name' => 'Globális A', 'active' => true, 'sort_order' => 1]);
        JobPosition::create(['company_id' => $this->companyA->id, 'name' => 'Céges A', 'active' => true, 'sort_order' => 2]);
        JobPosition::create(['company_id' => $this->companyB->id, 'name' => 'Másik cég', 'active' => true, 'sort_order' => 3]);
        JobPosition::create(['company_id' => $this->companyA->id, 'name' => 'Inaktív', 'active' => false, 'sort_order' => 4]);

        $response = $this->asAdmin($this->companyA)->getJson('/api/job-positions');

        $response->assertOk()->assertJsonCount(2, 'data');
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains('Globális A', $names);
        $this->assertContains('Céges A', $names);
        $this->assertNotContains('Másik cég', $names);
        $this->assertNotContains('Inaktív', $names);
    }

    public function test_index_does_not_require_manage_permission(): void
    {
        JobPosition::create(['company_id' => null, 'name' => 'Globális', 'active' => true]);

        $user = $this->makeUser();
        $user->companies()->attach($this->companyA->id, ['is_default' => true]);
        // Szándékosan nincs job_position.manage jog hozzárendelve.

        $this->asUser($user, $this->companyA)
            ->getJson('/api/job-positions')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_index_all_param_includes_inactive_for_manage_permission_holder(): void
    {
        JobPosition::create(['company_id' => $this->companyA->id, 'name' => 'Aktív', 'active' => true]);
        JobPosition::create(['company_id' => $this->companyA->id, 'name' => 'Inaktív', 'active' => false]);
        $user = $this->makeUserWithManagePermission($this->companyA);

        $this->asUser($user, $this->companyA)
            ->getJson('/api/job-positions?all=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_index_all_param_ignored_without_manage_permission(): void
    {
        JobPosition::create(['company_id' => $this->companyA->id, 'name' => 'Aktív', 'active' => true]);
        JobPosition::create(['company_id' => $this->companyA->id, 'name' => 'Inaktív', 'active' => false]);

        $user = $this->makeUser();
        $user->companies()->attach($this->companyA->id, ['is_default' => true]);

        $this->asUser($user, $this->companyA)
            ->getJson('/api/job-positions?all=1')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_index_all_param_includes_inactive_for_superadmin(): void
    {
        JobPosition::create(['company_id' => $this->companyA->id, 'name' => 'Aktív', 'active' => true]);
        JobPosition::create(['company_id' => $this->companyA->id, 'name' => 'Inaktív', 'active' => false]);

        $this->asAdmin($this->companyA)
            ->getJson('/api/job-positions?all=1')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // store — céges admin csak saját cégéhez; globálisat csak superadmin
    // ══════════════════════════════════════════════════════════════════════════

    public function test_company_admin_with_manage_permission_can_create_company_scoped_position(): void
    {
        $user = $this->makeUserWithManagePermission($this->companyA);

        $response = $this->asUser($user, $this->companyA)
            ->postJson('/api/job-positions', ['name' => 'Pénztáros'])
            ->assertCreated();

        $response->assertJsonPath('data.company_id', $this->companyA->id);
        $response->assertJsonPath('data.is_global', false);
        $this->assertDatabaseHas('job_positions', ['name' => 'Pénztáros', 'company_id' => $this->companyA->id]);
    }

    public function test_company_admin_cannot_create_global_position(): void
    {
        $user = $this->makeUserWithManagePermission($this->companyA);

        $this->asUser($user, $this->companyA)
            ->postJson('/api/job-positions', ['name' => 'Globális próba', 'global' => true])
            ->assertForbidden();

        $this->assertDatabaseMissing('job_positions', ['name' => 'Globális próba']);
    }

    public function test_superadmin_can_create_global_position(): void
    {
        $response = $this->asAdmin($this->companyA)
            ->postJson('/api/job-positions', ['name' => 'Ügyvezető', 'global' => true])
            ->assertCreated();

        $response->assertJsonPath('data.company_id', null);
        $response->assertJsonPath('data.is_global', true);
        $this->assertDatabaseHas('job_positions', ['name' => 'Ügyvezető', 'company_id' => null]);
    }

    public function test_store_requires_manage_permission(): void
    {
        $user = $this->makeUser();
        $user->companies()->attach($this->companyA->id, ['is_default' => true]);

        $this->asUser($user, $this->companyA)
            ->postJson('/api/job-positions', ['name' => 'Nincs jog'])
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // update / destroy — cross-tenant védelem + globális szabály
    // ══════════════════════════════════════════════════════════════════════════

    public function test_company_admin_cannot_update_another_companys_position(): void
    {
        $position = JobPosition::create(['company_id' => $this->companyB->id, 'name' => 'Másik cégé', 'active' => true]);
        $user = $this->makeUserWithManagePermission($this->companyA);

        $this->asUser($user, $this->companyA)
            ->putJson("/api/job-positions/{$position->id}", ['name' => 'Átírva'])
            ->assertNotFound();
    }

    public function test_company_admin_cannot_update_global_position(): void
    {
        $position = JobPosition::create(['company_id' => null, 'name' => 'Globális', 'active' => true]);
        $user = $this->makeUserWithManagePermission($this->companyA);

        $this->asUser($user, $this->companyA)
            ->putJson("/api/job-positions/{$position->id}", ['name' => 'Átírva'])
            ->assertForbidden();

        $position->refresh();
        $this->assertSame('Globális', $position->name);
    }

    public function test_company_admin_cannot_delete_global_position(): void
    {
        $position = JobPosition::create(['company_id' => null, 'name' => 'Globális', 'active' => true]);
        $user = $this->makeUserWithManagePermission($this->companyA);

        $this->asUser($user, $this->companyA)
            ->deleteJson("/api/job-positions/{$position->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('job_positions', ['id' => $position->id]);
    }

    public function test_superadmin_can_update_global_position(): void
    {
        $position = JobPosition::create(['company_id' => null, 'name' => 'Régi név', 'active' => true]);

        $this->asAdmin($this->companyA)
            ->putJson("/api/job-positions/{$position->id}", ['name' => 'Új név'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Új név');
    }

    public function test_company_admin_can_update_own_company_position(): void
    {
        $position = JobPosition::create(['company_id' => $this->companyA->id, 'name' => 'Régi', 'active' => true]);
        $user = $this->makeUserWithManagePermission($this->companyA);

        $this->asUser($user, $this->companyA)
            ->putJson("/api/job-positions/{$position->id}", ['name' => 'Friss', 'active' => false])
            ->assertOk()
            ->assertJsonPath('data.name', 'Friss')
            ->assertJsonPath('data.active', false);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Törlés-szabály: hivatkozott sor nem törölhető hard-delete-tel (409)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_referenced_position_cannot_be_hard_deleted(): void
    {
        $position = JobPosition::create(['company_id' => $this->companyA->id, 'name' => 'Foglalt', 'active' => true]);
        $referencingUser = $this->makeUser();
        $referencingUser->update(['job_position_id' => $position->id]);
        $user = $this->makeUserWithManagePermission($this->companyA);

        $this->asUser($user, $this->companyA)
            ->deleteJson("/api/job-positions/{$position->id}")
            ->assertConflict();

        $this->assertDatabaseHas('job_positions', ['id' => $position->id]);
    }

    public function test_unreferenced_position_can_be_deleted(): void
    {
        $position = JobPosition::create(['company_id' => $this->companyA->id, 'name' => 'Törölhető', 'active' => true]);
        $user = $this->makeUserWithManagePermission($this->companyA);

        $this->asUser($user, $this->companyA)
            ->deleteJson("/api/job-positions/{$position->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('job_positions', ['id' => $position->id]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Név-egyediség a láthatósági körön belül
    // ══════════════════════════════════════════════════════════════════════════

    public function test_store_rejects_duplicate_name_within_same_company(): void
    {
        JobPosition::create(['company_id' => $this->companyA->id, 'name' => 'Recepciós', 'active' => true]);
        $user = $this->makeUserWithManagePermission($this->companyA);

        $this->asUser($user, $this->companyA)
            ->postJson('/api/job-positions', ['name' => 'Recepciós'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_store_allows_same_name_in_different_company(): void
    {
        JobPosition::create(['company_id' => $this->companyB->id, 'name' => 'Recepciós', 'active' => true]);
        $user = $this->makeUserWithManagePermission($this->companyA);

        $this->asUser($user, $this->companyA)
            ->postJson('/api/job-positions', ['name' => 'Recepciós'])
            ->assertCreated();
    }

    public function test_store_rejects_duplicate_name_within_global_scope(): void
    {
        JobPosition::create(['company_id' => null, 'name' => 'Ügyvezető', 'active' => true]);

        $this->asAdmin($this->companyA)
            ->postJson('/api/job-positions', ['name' => 'Ügyvezető', 'global' => true])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_company_scoped_name_does_not_collide_with_same_name_in_global_scope(): void
    {
        JobPosition::create(['company_id' => null, 'name' => 'Recepciós', 'active' => true]);
        $user = $this->makeUserWithManagePermission($this->companyA);

        $this->asUser($user, $this->companyA)
            ->postJson('/api/job-positions', ['name' => 'Recepciós'])
            ->assertCreated();
    }

    public function test_update_rejects_duplicate_name_within_same_scope_excluding_self(): void
    {
        $position = JobPosition::create(['company_id' => $this->companyA->id, 'name' => 'Eredeti', 'active' => true]);
        JobPosition::create(['company_id' => $this->companyA->id, 'name' => 'Foglalt név', 'active' => true]);
        $user = $this->makeUserWithManagePermission($this->companyA);

        $this->asUser($user, $this->companyA)
            ->putJson("/api/job-positions/{$position->id}", ['name' => 'Foglalt név'])
            ->assertUnprocessable();

        // Önmagával szemben (változatlan név mellett) nem hasal el.
        $this->asUser($user, $this->companyA)
            ->putJson("/api/job-positions/{$position->id}", ['name' => 'Eredeti'])
            ->assertOk();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════════

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

    private function makeUserWithManagePermission(Company $company): User
    {
        $user = $this->makeUser();
        $user->companies()->attach($company->id, ['is_default' => true]);

        app(CurrentCompany::class)->set($company->id);
        $group = Group::create(['name' => 'Munkakör-kezelők '.self::$seq]);
        $group->users()->attach($user->id);
        $group->permissions()->attach($this->permManage->id);
        app(CurrentCompany::class)->clear();

        return $user;
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
