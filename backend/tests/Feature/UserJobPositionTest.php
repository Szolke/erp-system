<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\JobPosition;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Munkakör (job_position) — 3. lépés: user create/update job_position_id
 * validáció + láthatósági kör.
 *
 * A job_position_id validáció a StoreAssetRequest asset_type_id mintáját
 * követi: Rule::exists a job_positions táblán, "globális VAGY aktuális cég"
 * where-closure-ral, kiegészítve az 'active' feltétellel. Update-nél az
 * 'active' feltétel csak akkor érvényesül, ha a beküldött érték ténylegesen
 * ELTÉR a user jelenlegi munkakörétől (UserController::update).
 */
class UserJobPositionTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private Company $companyA;
    private Company $companyB;
    private User $superadmin;

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
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // store
    // ══════════════════════════════════════════════════════════════════════════

    public function test_user_can_be_created_with_global_job_position(): void
    {
        $position = JobPosition::create(['company_id' => null, 'name' => 'Ügyvezető', 'active' => true]);

        $response = $this->asAdmin($this->companyA)
            ->postJson('/api/users', [
                'name'            => 'Kovács Béla',
                'email'           => 'bela@example.com',
                'job_position_id' => $position->id,
            ])
            ->assertCreated();

        $response->assertJsonPath('job_position.id', $position->id);
        $this->assertDatabaseHas('users', ['email' => 'bela@example.com', 'job_position_id' => $position->id]);
    }

    public function test_user_can_be_created_with_own_company_job_position(): void
    {
        $position = JobPosition::create(['company_id' => $this->companyA->id, 'name' => 'Pénztáros', 'active' => true]);

        $response = $this->asAdmin($this->companyA)
            ->postJson('/api/users', [
                'name'            => 'Nagy Éva',
                'email'           => 'eva@example.com',
                'job_position_id' => $position->id,
            ])
            ->assertCreated();

        $response->assertJsonPath('job_position.id', $position->id);
    }

    public function test_user_cannot_be_created_with_another_companys_job_position(): void
    {
        $position = JobPosition::create(['company_id' => $this->companyB->id, 'name' => 'Másik cégé', 'active' => true]);

        $this->asAdmin($this->companyA)
            ->postJson('/api/users', [
                'name'            => 'Teszt',
                'email'           => 'teszt1@example.com',
                'job_position_id' => $position->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('job_position_id');

        $this->assertDatabaseMissing('users', ['email' => 'teszt1@example.com']);
    }

    public function test_user_cannot_be_created_with_inactive_job_position(): void
    {
        $position = JobPosition::create(['company_id' => $this->companyA->id, 'name' => 'Inaktív', 'active' => false]);

        $this->asAdmin($this->companyA)
            ->postJson('/api/users', [
                'name'            => 'Teszt',
                'email'           => 'teszt2@example.com',
                'job_position_id' => $position->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('job_position_id');
    }

    public function test_user_can_be_created_without_job_position(): void
    {
        $this->asAdmin($this->companyA)
            ->postJson('/api/users', [
                'name'  => 'Teszt',
                'email' => 'teszt3@example.com',
            ])
            ->assertCreated()
            ->assertJsonPath('job_position_id', null)
            ->assertJsonPath('job_position', null);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // update
    // ══════════════════════════════════════════════════════════════════════════

    public function test_user_job_position_can_be_updated_to_own_company_position(): void
    {
        $user = $this->makeCompanyUser($this->companyA);
        $position = JobPosition::create(['company_id' => $this->companyA->id, 'name' => 'Recepciós', 'active' => true]);

        $response = $this->asAdmin($this->companyA)
            ->putJson("/api/users/{$user->id}", ['job_position_id' => $position->id])
            ->assertOk();

        $response->assertJsonPath('job_position.id', $position->id);
        $user->refresh();
        $this->assertSame($position->id, $user->job_position_id);
    }

    public function test_user_job_position_can_be_updated_to_global_position(): void
    {
        $user = $this->makeCompanyUser($this->companyA);
        $position = JobPosition::create(['company_id' => null, 'name' => 'Globális', 'active' => true]);

        $this->asAdmin($this->companyA)
            ->putJson("/api/users/{$user->id}", ['job_position_id' => $position->id])
            ->assertOk()
            ->assertJsonPath('job_position.id', $position->id);
    }

    public function test_user_job_position_cannot_be_updated_to_another_companys_position(): void
    {
        $user = $this->makeCompanyUser($this->companyA);
        $position = JobPosition::create(['company_id' => $this->companyB->id, 'name' => 'Másik cégé', 'active' => true]);

        $this->asAdmin($this->companyA)
            ->putJson("/api/users/{$user->id}", ['job_position_id' => $position->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('job_position_id');

        $user->refresh();
        $this->assertNull($user->job_position_id);
    }

    public function test_user_job_position_cannot_be_updated_to_inactive_position(): void
    {
        $user = $this->makeCompanyUser($this->companyA);
        $position = JobPosition::create(['company_id' => $this->companyA->id, 'name' => 'Inaktív', 'active' => false]);

        $this->asAdmin($this->companyA)
            ->putJson("/api/users/{$user->id}", ['job_position_id' => $position->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('job_position_id');
    }

    public function test_user_job_position_can_be_cleared_with_null(): void
    {
        $position = JobPosition::create(['company_id' => $this->companyA->id, 'name' => 'Régi', 'active' => true]);
        $user = $this->makeCompanyUser($this->companyA, $position->id);

        $this->asAdmin($this->companyA)
            ->putJson("/api/users/{$user->id}", ['job_position_id' => null])
            ->assertOk()
            ->assertJsonPath('job_position_id', null);

        $user->refresh();
        $this->assertNull($user->job_position_id);
    }

    public function test_update_does_not_force_removal_of_already_inactive_job_position_when_unchanged(): void
    {
        $position = JobPosition::create(['company_id' => $this->companyA->id, 'name' => 'Régi munkakör', 'active' => true]);
        $user = $this->makeCompanyUser($this->companyA, $position->id);

        // A munkakör időközben inaktívvá válik — a usernél marad, amíg nem
        // módosítják explicit módon egy másik értékre.
        $position->update(['active' => false]);

        $this->asAdmin($this->companyA)
            ->putJson("/api/users/{$user->id}", ['name' => 'Más név', 'job_position_id' => $position->id])
            ->assertOk();

        $user->refresh();
        $this->assertSame($position->id, $user->job_position_id);
    }

    public function test_update_without_job_position_field_leaves_it_unchanged(): void
    {
        $position = JobPosition::create(['company_id' => $this->companyA->id, 'name' => 'Régi', 'active' => true]);
        $user = $this->makeCompanyUser($this->companyA, $position->id);

        $this->asAdmin($this->companyA)
            ->putJson("/api/users/{$user->id}", ['name' => 'Más név'])
            ->assertOk();

        $user->refresh();
        $this->assertSame($position->id, $user->job_position_id);
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

    private function makeCompanyUser(Company $company, ?int $jobPositionId = null): User
    {
        $user = $this->makeUser();
        $user->update(['job_position_id' => $jobPositionId]);
        $user->companies()->attach($company->id, ['is_default' => true]);

        return $user;
    }

    private function asAdmin(Company $company): static
    {
        return $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $company->id])
            ->withHeader('X-Company-Id', (string) $company->id);
    }
}
