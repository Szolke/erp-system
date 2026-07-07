<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Token-eszközkezelő végpontok tesztjei.
 *
 * GET  /api/users/{user}/tokens          — tokenek listázása
 * DELETE /api/users/{user}/tokens/{id}   — token visszavonása
 *
 * Jogosultság:
 *   - Superadmin: bármely user tokenjeit kezelheti.
 *   - Normál user: KIZÁRÓLAG saját tokenjeit (ownership-alapú, nem RBAC-permission).
 *   - tokenId csak akkor távolítható el, ha ténylegesen a {user}-hez tartozik → 404 egyébként.
 */
class TokenManagementTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User    $superadmin;
    private User    $normalUser;
    private User    $otherUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name'                => 'Token Mgmt Teszt Kft.',
            'tax_number'          => '44444444-4-44',
            'registration_number' => '01-09-222222',
            'postal_code'         => '1000',
            'city'                => 'Budapest',
            'address_line'        => 'Kezelő utca 1.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
        ]);

        $this->superadmin = User::create([
            'name'               => 'Superadmin',
            'email'              => 'super@test.dev',
            'password'           => Hash::make('secret'),
            'is_active'          => true,
            'is_superadmin'      => true,
            'default_company_id' => $this->company->id,
        ]);
        $this->superadmin->companies()->attach($this->company->id);

        $this->normalUser = User::create([
            'name'               => 'Normal User',
            'email'              => 'normal@test.dev',
            'password'           => Hash::make('secret'),
            'is_active'          => true,
            'is_superadmin'      => false,
            'default_company_id' => $this->company->id,
        ]);
        $this->normalUser->companies()->attach($this->company->id);

        $this->otherUser = User::create([
            'name'               => 'Other User',
            'email'              => 'other@test.dev',
            'password'           => Hash::make('secret'),
            'is_active'          => true,
            'is_superadmin'      => false,
            'default_company_id' => $this->company->id,
        ]);
        $this->otherUser->companies()->attach($this->company->id);
    }

    // ── 1. Listázás ──────────────────────────────────────────────────────────

    public function test_superadmin_can_list_any_users_tokens(): void
    {
        $this->normalUser->createToken('iPhone');
        $this->normalUser->createToken('Android');

        $res = $this->asSuperadmin()->getJson("/api/users/{$this->normalUser->id}/tokens");

        $res->assertOk();
        $this->assertCount(2, $res->json('data'));
        // Token-hash (sensitive) nem kerülhet a válaszba
        $this->assertArrayNotHasKey('token', $res->json('data')[0]);
    }

    public function test_normal_user_can_list_own_tokens(): void
    {
        $this->normalUser->createToken('Saját eszköz');

        $res = $this->asNormalUser()->getJson("/api/users/{$this->normalUser->id}/tokens");

        $res->assertOk();
        $this->assertCount(1, $res->json('data'));
        $this->assertEquals('Saját eszköz', $res->json('data')[0]['name']);
    }

    public function test_normal_user_cannot_list_other_users_tokens(): void
    {
        $this->otherUser->createToken('Other device');

        $this->asNormalUser()
            ->getJson("/api/users/{$this->otherUser->id}/tokens")
            ->assertForbidden();
    }

    // ── 2. Törlés ────────────────────────────────────────────────────────────

    public function test_normal_user_can_delete_own_token_and_bearer_becomes_invalid(): void
    {
        $newToken   = $this->normalUser->createToken('My iPhone');
        $plainToken = $newToken->plainTextToken;
        $tokenId    = $newToken->accessToken->id;

        $this->asNormalUser()
            ->deleteJson("/api/users/{$this->normalUser->id}/tokens/{$tokenId}")
            ->assertNoContent();

        // Sanctum cache-eli a feloldott usert a guard-on; nullázzuk, hogy a
        // DB-törlés érvényesüljön a következő kérés hitelesítésekor.
        $this->app['auth']->forgetGuards();

        $this->withToken($plainToken)
            ->getJson('/api/me')
            ->assertUnauthorized();
    }

    public function test_normal_user_cannot_delete_other_users_token(): void
    {
        $newToken = $this->otherUser->createToken('Other iPhone');
        $tokenId  = $newToken->accessToken->id;

        $this->asNormalUser()
            ->deleteJson("/api/users/{$this->otherUser->id}/tokens/{$tokenId}")
            ->assertForbidden();

        $this->assertNotNull(PersonalAccessToken::find($tokenId));
    }

    public function test_superadmin_can_delete_any_users_token(): void
    {
        $newToken = $this->normalUser->createToken('Normal device');
        $tokenId  = $newToken->accessToken->id;

        $this->asSuperadmin()
            ->deleteJson("/api/users/{$this->normalUser->id}/tokens/{$tokenId}")
            ->assertNoContent();

        $this->assertNull(PersonalAccessToken::find($tokenId));
    }

    public function test_cannot_delete_token_belonging_to_different_user(): void
    {
        // Superadmin helyes URL-lel ad meg másik userhez tartozó tokenId-t →
        // a {user}/tokens/{tokenId} párosítás nem stimmel → 404.
        $newToken = $this->otherUser->createToken('Other device');
        $tokenId  = $newToken->accessToken->id;

        $this->asSuperadmin()
            ->deleteJson("/api/users/{$this->normalUser->id}/tokens/{$tokenId}")
            ->assertNotFound();

        $this->assertNotNull(PersonalAccessToken::find($tokenId));
    }

    // ── Segédmetódusok ───────────────────────────────────────────────────────

    private function asSuperadmin(): static
    {
        return $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->company->id])
            ->withHeader('X-Company-Id', (string) $this->company->id);
    }

    private function asNormalUser(): static
    {
        return $this->actingAs($this->normalUser)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->company->id])
            ->withHeader('X-Company-Id', (string) $this->company->id);
    }
}
