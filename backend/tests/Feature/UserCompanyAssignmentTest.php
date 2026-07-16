<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * User–cég hozzárendelés tesztjei.
 * Csak superadmin végezheti el; a Company és User route model binding
 * company-scope nélkül működik (sem User, sem Company nem hordoz BelongsToCompany scope-ot).
 */
class UserCompanyAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;
    private Company $companyB;
    private User    $superadmin;
    private User    $targetUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA   = $this->makeCompany('A Kft.', '11111111-1-11');
        $this->companyB   = $this->makeCompany('B Kft.', '22222222-2-22');

        $this->superadmin = User::create([
            'name'          => 'Superadmin',
            'email'         => 'superadmin@test.dev',
            'password'      => Hash::make('password'),
            'is_superadmin' => true,
            'is_active'     => true,
        ]);
        $this->superadmin->companies()->attach($this->companyA->id);

        $this->targetUser = User::create([
            'name'          => 'Teszt User',
            'email'         => 'user@test.dev',
            'password'      => Hash::make('password'),
            'is_superadmin' => false,
            'is_active'     => true,
        ]);
        $this->targetUser->companies()->attach($this->companyA->id);
    }

    // 1. Superadmin lekéri a user cégeit
    public function test_superadmin_can_list_user_companies(): void
    {
        $this->targetUser->companies()->attach($this->companyB->id);

        $res = $this->asSuperadmin()->getJson("/api/users/{$this->targetUser->id}/companies");

        $res->assertOk();
        $ids = array_column($res->json('data'), 'id');
        $this->assertContains($this->companyA->id, $ids);
        $this->assertContains($this->companyB->id, $ids);
        $this->assertCount(2, $ids);
    }

    // 2. Superadmin hozzárendel egy usert egy céghez
    public function test_superadmin_can_attach_user_to_company(): void
    {
        $res = $this->asSuperadmin()
            ->postJson("/api/users/{$this->targetUser->id}/companies/{$this->companyB->id}");

        $res->assertOk();
        $this->assertTrue(
            $this->targetUser->companies()->whereKey($this->companyB->id)->exists()
        );
    }

    // 3. Az attach válasza visszaadja a frissített céglistát (id, name, tax_number)
    public function test_attach_response_contains_updated_company_list(): void
    {
        $res = $this->asSuperadmin()
            ->postJson("/api/users/{$this->targetUser->id}/companies/{$this->companyB->id}");

        $res->assertOk()->assertJsonStructure(['data' => [['id', 'name', 'tax_number']]]);
        $ids = array_column($res->json('data'), 'id');
        $this->assertContains($this->companyA->id, $ids);
        $this->assertContains($this->companyB->id, $ids);
    }

    // 4. Kétszeri attach idempotens — csak egy pivot sor keletkezik
    public function test_attach_is_idempotent(): void
    {
        $this->asSuperadmin()
            ->postJson("/api/users/{$this->targetUser->id}/companies/{$this->companyB->id}");
        $this->asSuperadmin()
            ->postJson("/api/users/{$this->targetUser->id}/companies/{$this->companyB->id}");

        // companyA (setUp) + companyB (attach), semmi duplikát
        $this->assertCount(2, $this->targetUser->companies()->get());
    }

    // 5. Superadmin leválaszt egy usert egy cégről
    public function test_superadmin_can_detach_user_from_company(): void
    {
        $this->targetUser->companies()->attach($this->companyB->id);

        $res = $this->asSuperadmin()
            ->deleteJson("/api/users/{$this->targetUser->id}/companies/{$this->companyB->id}");

        $res->assertNoContent();
        $this->assertFalse(
            $this->targetUser->companies()->whereKey($this->companyB->id)->exists()
        );
    }

    // 5b. Ha a leválasztott cég volt a user default_company_id-je, átáll egy megmaradó cégre
    //     (regresszió: EnsureCompanyContext a default_company_id-ra esik vissza session/header
    //     nélkül — friss bejelentkezés után egy stale érték azonnal 403-at dob).
    public function test_detach_reassigns_default_company_id_when_it_was_the_detached_company(): void
    {
        $this->targetUser->companies()->attach($this->companyB->id);
        $this->targetUser->update(['default_company_id' => $this->companyA->id]);

        $this->asSuperadmin()
            ->deleteJson("/api/users/{$this->targetUser->id}/companies/{$this->companyA->id}")
            ->assertNoContent();

        $this->targetUser->refresh();
        $this->assertSame($this->companyB->id, $this->targetUser->default_company_id);
    }

    // 5c. Ha a leválasztott cég NEM volt a default, a default_company_id változatlan marad
    public function test_detach_does_not_change_default_company_id_when_different_company_detached(): void
    {
        $this->targetUser->companies()->attach($this->companyB->id);
        $this->targetUser->update(['default_company_id' => $this->companyA->id]);

        $this->asSuperadmin()
            ->deleteJson("/api/users/{$this->targetUser->id}/companies/{$this->companyB->id}")
            ->assertNoContent();

        $this->targetUser->refresh();
        $this->assertSame($this->companyA->id, $this->targetUser->default_company_id);
    }

    // 6. Az utolsó cégről is leválasztható (0-ra csökkenés engedett)
    public function test_detach_from_last_company_is_rejected(): void
    {
        // targetUser csak companyA-ban van (setUp) — utolsó cégből nem lehet kivenni
        $res = $this->asSuperadmin()
            ->deleteJson("/api/users/{$this->targetUser->id}/companies/{$this->companyA->id}");

        $res->assertStatus(422);
        $res->assertJsonFragment(['message' => 'A felhasználó legalább egy céghez kell tartozzon. Törlés előtt rendelje hozzá egy másik céghez.']);
        $this->assertCount(1, $this->targetUser->companies()->get());
    }

    // 7. Nem fennálló tagság detach-elése → 204 (idempotens)
    public function test_detach_nonexistent_membership_returns_204(): void
    {
        // targetUser nem tagja companyB-nek
        $res = $this->asSuperadmin()
            ->deleteJson("/api/users/{$this->targetUser->id}/companies/{$this->companyB->id}");

        $res->assertNoContent();
    }

    // 8–10. Nem-superadmin → 403 mindhárom endpointon
    public function test_non_superadmin_cannot_list_user_companies(): void
    {
        $this->asNormalUser()
            ->getJson("/api/users/{$this->targetUser->id}/companies")
            ->assertForbidden();
    }

    public function test_non_superadmin_cannot_attach_user_to_company(): void
    {
        $this->asNormalUser()
            ->postJson("/api/users/{$this->targetUser->id}/companies/{$this->companyB->id}")
            ->assertForbidden();
    }

    public function test_non_superadmin_cannot_detach_user_from_company(): void
    {
        $this->asNormalUser()
            ->deleteJson("/api/users/{$this->targetUser->id}/companies/{$this->companyA->id}")
            ->assertForbidden();
    }

    // 11. Nem létező user → 404
    public function test_nonexistent_user_returns_404(): void
    {
        $this->asSuperadmin()
            ->getJson('/api/users/99999/companies')
            ->assertNotFound();
    }

    // 12. Nem létező company → 404
    public function test_nonexistent_company_returns_404_on_attach(): void
    {
        $this->asSuperadmin()
            ->postJson("/api/users/{$this->targetUser->id}/companies/99999")
            ->assertNotFound();
    }

    public function test_nonexistent_company_returns_404_on_detach(): void
    {
        $this->asSuperadmin()
            ->deleteJson("/api/users/{$this->targetUser->id}/companies/99999")
            ->assertNotFound();
    }

    // ── Segédmetódusok ──────────────────────────────────────────────────────────

    private function asSuperadmin(): static
    {
        return $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->companyA->id])
            ->withHeader('X-Company-Id', (string) $this->companyA->id);
    }

    private function asNormalUser(): static
    {
        $user = User::create([
            'name'     => 'Normal',
            'email'    => 'normal@test.dev',
            'password' => Hash::make('password'),
        ]);
        $user->companies()->attach($this->companyA->id);

        return $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->companyA->id])
            ->withHeader('X-Company-Id', (string) $this->companyA->id);
    }

    private function makeCompany(string $name, string $taxNumber): Company
    {
        return Company::create([
            'name'                => $name,
            'tax_number'          => $taxNumber,
            'registration_number' => '01-09-000001',
            'postal_code'         => '1000',
            'city'                => 'Budapest',
            'address_line'        => 'Teszt utca 1.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
        ]);
    }
}
