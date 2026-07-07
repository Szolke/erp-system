<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

/**
 * Token-alapú hitelesítés tesztjei (POST /api/auth/token + /api/auth/token/revoke).
 *
 * Bizonyítja:
 *  - token-kiadás helyes/hibás hitelesítőkkel
 *  - a kiadott token végigmegy az auth:sanctum + company.context + RBAC láncon
 *  - token-visszavonás érvényteleníti a hozzáférést
 *  - rate limit véd brute-force ellen
 *  - RBAC token-úton is érvényesül
 */
class TokenAuthTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // A rate limiter az array cache-ben él — minden tesztnél nullázzuk,
        // hogy a throttle:5,1 tesztek ne akkumulálódjanak egymásba.
        Cache::flush();

        $this->company = $this->makeCompany();

        $this->user = User::create([
            'name'               => 'Token Teszt User',
            'email'              => 'tokenuser@test.dev',
            'password'           => Hash::make('secret123'),
            'is_active'          => true,
            'is_superadmin'      => false,
            'default_company_id' => $this->company->id,
        ]);
        $this->user->companies()->attach($this->company->id);
    }

    // ── 1. Token kiadás ────────────────────────────────────────────────────────

    public function test_token_issued_for_valid_credentials(): void
    {
        $res = $this->postJson('/api/auth/token', [
            'email'       => 'tokenuser@test.dev',
            'password'    => 'secret123',
            'device_name' => 'Tesztelő iPhone',
        ]);

        $res->assertOk()
            ->assertJsonStructure(['token', 'user' => ['id', 'name', 'email'], 'default_company']);

        // A plain-text token az {id}|{hash} formátum — ellenőrizzük, hogy létezik az id-je a DB-ben
        $parts = explode('|', $res->json('token'), 2);
        $this->assertCount(2, $parts, 'A token formátuma nem {id}|{hash}.');
        $this->assertNotNull(
            PersonalAccessToken::find($parts[0]),
            'A kiadott token nem található a personal_access_tokens táblában.'
        );
    }

    public function test_response_contains_default_company_data(): void
    {
        $res = $this->postJson('/api/auth/token', [
            'email'       => 'tokenuser@test.dev',
            'password'    => 'secret123',
            'device_name' => 'Test',
        ]);

        $res->assertOk()
            ->assertJsonPath('default_company.id', $this->company->id)
            ->assertJsonPath('default_company.name', $this->company->name);
    }

    public function test_invalid_password_returns_401(): void
    {
        $res = $this->postJson('/api/auth/token', [
            'email'       => 'tokenuser@test.dev',
            'password'    => 'rosszjelszo',
            'device_name' => 'Test',
        ]);

        $res->assertStatus(401)
            ->assertJsonFragment(['message' => 'Hibás e-mail cím vagy jelszó.']);

        $this->assertCount(0, PersonalAccessToken::all());
    }

    public function test_unknown_email_returns_401(): void
    {
        $res = $this->postJson('/api/auth/token', [
            'email'       => 'nemletezik@test.dev',
            'password'    => 'secret123',
            'device_name' => 'Test',
        ]);

        $res->assertStatus(401)
            ->assertJsonFragment(['message' => 'Hibás e-mail cím vagy jelszó.']);
    }

    public function test_inactive_user_cannot_get_token(): void
    {
        $this->user->update(['is_active' => false]);

        $res = $this->postJson('/api/auth/token', [
            'email'       => 'tokenuser@test.dev',
            'password'    => 'secret123',
            'device_name' => 'Test',
        ]);

        $res->assertStatus(403)
            ->assertJsonFragment(['message' => 'A felhasználó fiókja inaktív.']);
    }

    public function test_device_name_is_required(): void
    {
        $this->postJson('/api/auth/token', [
            'email'    => 'tokenuser@test.dev',
            'password' => 'secret123',
        ])->assertUnprocessable();
    }

    // ── 2. Token mint hitelesítés: auth:sanctum + company.context lánc ────────

    public function test_token_authenticates_protected_endpoint(): void
    {
        $plainToken = $this->issueToken();

        $res = $this->withToken($plainToken)->getJson('/api/me');

        $res->assertOk()
            ->assertJsonPath('user.id', $this->user->id);
    }

    public function test_company_context_resolved_via_default_company_id(): void
    {
        // A kérés nem küld X-Company-Id headert, sem session nincs —
        // az EnsureCompanyContext a default_company_id mezőre esik vissza.
        $plainToken = $this->issueToken();

        $res = $this->withToken($plainToken)->getJson('/api/me');

        $res->assertOk()
            ->assertJsonPath('active_company_id', $this->company->id);
    }

    public function test_invalid_token_returns_401(): void
    {
        $this->withToken('1|hamis_token_string')
            ->getJson('/api/me')
            ->assertUnauthorized();
    }

    // ── 3. Token visszavonás ──────────────────────────────────────────────────

    public function test_revoke_invalidates_token(): void
    {
        $plainToken = $this->issueToken();

        // Visszavonás
        $this->withToken($plainToken)
            ->postJson('/api/auth/token/revoke')
            ->assertNoContent();

        // Sanctum a guard-on cache-eli a feloldott usert; ugyanazon app-instance-n belül
        // több HTTP kérés esetén a cache-t nullázni kell, hogy a DB-törlés érvényesüljön.
        $this->app['auth']->forgetGuards();

        // Visszavont tokennel a védett endpoint 401-et ad
        $this->withToken($plainToken)
            ->getJson('/api/me')
            ->assertUnauthorized();
    }

    public function test_revoke_without_auth_returns_401(): void
    {
        $this->postJson('/api/auth/token/revoke')
            ->assertUnauthorized();
    }

    public function test_revoke_with_session_auth_returns_422(): void
    {
        // actingAs() session-alapú (TransientToken) hitelesítést szimulál;
        // nincs valódi PersonalAccessToken → 422
        $this->actingAs($this->user)
            ->postJson('/api/auth/token/revoke')
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'A munkamenet cookie-alapú; nincs visszavonható Bearer token.']);
    }

    // ── 4. Rate limiting ──────────────────────────────────────────────────────

    public function test_rate_limit_blocks_after_5_attempts(): void
    {
        $payload = [
            'email'       => 'tokenuser@test.dev',
            'password'    => 'rosszjelszo',
            'device_name' => 'Test',
        ];

        // 5 sikertelen kísérlet — mind 401
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/token', $payload)->assertStatus(401);
        }

        // A 6. kísérlet 429-et kap (throttle:5,1)
        $this->postJson('/api/auth/token', $payload)->assertStatus(429);
    }

    // ── 5. RBAC token-úton ────────────────────────────────────────────────────

    public function test_rbac_enforced_for_non_superadmin_token(): void
    {
        // A user nem superadmin, nincs csoport/jog → a superadmin-only endpoint 403-at kell adjon
        $plainToken = $this->issueToken();

        // GET /api/users/{id}/companies abort_unless(is_superadmin, 403)-t tartalmaz
        $this->withToken($plainToken)
            ->getJson("/api/users/{$this->user->id}/companies")
            ->assertForbidden();
    }

    public function test_superadmin_token_passes_rbac(): void
    {
        $superadmin = User::create([
            'name'               => 'Super',
            'email'              => 'super@test.dev',
            'password'           => Hash::make('secret123'),
            'is_active'          => true,
            'is_superadmin'      => true,
            'default_company_id' => $this->company->id,
        ]);
        $superadmin->companies()->attach($this->company->id);

        $res = $this->postJson('/api/auth/token', [
            'email'       => 'super@test.dev',
            'password'    => 'secret123',
            'device_name' => 'Admin eszköz',
        ]);
        $res->assertOk();
        $superToken = $res->json('token');

        $this->withToken($superToken)
            ->getJson("/api/users/{$this->user->id}/companies")
            ->assertOk();
    }

    // ── Segédmetódusok ────────────────────────────────────────────────────────

    private function issueToken(): string
    {
        $res = $this->postJson('/api/auth/token', [
            'email'       => 'tokenuser@test.dev',
            'password'    => 'secret123',
            'device_name' => 'Teszt eszköz',
        ]);
        $res->assertOk();

        return $res->json('token');
    }

    private function makeCompany(): Company
    {
        return Company::create([
            'name'                => 'Token Teszt Kft.',
            'tax_number'          => '33333333-3-33',
            'registration_number' => '01-09-123456',
            'postal_code'         => '1000',
            'city'                => 'Budapest',
            'address_line'        => 'Token utca 1.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
        ]);
    }
}
