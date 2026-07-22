<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use App\Models\UserListPreference;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PUT/DELETE /api/list-preferences/{listKey} + a /api/me beágyazott
 * list_preferences kulcs. Oszlopválasztó — backend réteg, l. docs/progress.md.
 */
class ListPreferenceTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════
    // 1-2: PUT create + upsert (no duplicate)
    // ══════════════════════════════════════════════════════════════════════

    public function test_put_creates_new_row_for_authenticated_user_and_current_company(): void
    {
        [$company, $user] = $this->makeCompanyWithUser();

        $response = $this->asUser($user, $company)->putJson('/api/list-preferences/invoices.index', $this->payload());

        $response->assertOk();
        $response->assertJsonPath('data.list_key', 'invoices.index');
        $response->assertJsonPath('data.preferences.page_size', 25);

        $this->assertDatabaseCount('user_list_preferences', 1);
        $row = UserListPreference::withoutGlobalScope('company')->first();
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame($company->id, $row->company_id);
        $this->assertSame('invoices.index', $row->list_key);
    }

    public function test_put_second_time_updates_existing_row_without_duplicating(): void
    {
        [$company, $user] = $this->makeCompanyWithUser();
        $this->asUser($user, $company);

        $this->putJson('/api/list-preferences/invoices.index', $this->payload())->assertOk();
        $this->putJson('/api/list-preferences/invoices.index', $this->payload(['page_size' => 50]))
            ->assertOk()
            ->assertJsonPath('data.preferences.page_size', 50);

        $this->assertDatabaseCount('user_list_preferences', 1);
        $row = UserListPreference::withoutGlobalScope('company')->first();
        $this->assertSame(50, $row->preferences['page_size']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 3: DELETE — idempotent reset
    // ══════════════════════════════════════════════════════════════════════

    public function test_delete_removes_row_and_repeated_delete_is_idempotent(): void
    {
        [$company, $user] = $this->makeCompanyWithUser();
        $this->asUser($user, $company);

        $this->putJson('/api/list-preferences/invoices.index', $this->payload())->assertOk();
        $this->assertDatabaseCount('user_list_preferences', 1);

        $this->deleteJson('/api/list-preferences/invoices.index')->assertNoContent();
        $this->assertDatabaseCount('user_list_preferences', 0);

        // ismételt törlés is 204 — nincs hiba, ha már nincs sor
        $this->deleteJson('/api/list-preferences/invoices.index')->assertNoContent();
    }

    // ══════════════════════════════════════════════════════════════════════
    // 4: auth nélkül 401
    // ══════════════════════════════════════════════════════════════════════

    public function test_unauthenticated_requests_return_401(): void
    {
        $this->putJson('/api/list-preferences/invoices.index', $this->payload())->assertUnauthorized();
        $this->deleteJson('/api/list-preferences/invoices.index')->assertUnauthorized();
    }

    // ══════════════════════════════════════════════════════════════════════
    // 5: érvénytelen listKey → 404 (route constraint)
    // ══════════════════════════════════════════════════════════════════════

    public function test_invalid_list_key_format_returns_404(): void
    {
        [$company, $user] = $this->makeCompanyWithUser();
        $this->asUser($user, $company);

        // nagybetűs kulcs — a route regex csak [a-z0-9_.]-et enged
        $this->putJson('/api/list-preferences/Invoices.Index', $this->payload())->assertNotFound();

        // 65 karakteres kulcs — a route regex max 64 karaktert enged
        $tooLong = str_repeat('a', 65);
        $this->putJson('/api/list-preferences/'.$tooLong, $this->payload())->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════════════
    // 6: érvénytelen payload → 422
    // ══════════════════════════════════════════════════════════════════════

    public function test_invalid_sort_direction_returns_422(): void
    {
        [$company, $user] = $this->makeCompanyWithUser();
        $this->asUser($user, $company);

        $response = $this->putJson('/api/list-preferences/invoices.index', $this->payload([
            'sort' => ['by' => 'issue_date', 'dir' => 'sideways'],
        ]));

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('sort.dir');
    }

    public function test_payload_over_8kb_returns_422(): void
    {
        [$company, $user] = $this->makeCompanyWithUser();
        $this->asUser($user, $company);

        $longValues = array_fill(0, 100, str_repeat('a', 64));

        $response = $this->putJson('/api/list-preferences/invoices.index', [
            'columns' => ['visible' => $longValues, 'order' => $longValues],
        ]);

        $response->assertStatus(422);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 7: ismeretlen felső szintű kulcs nem kerül a DB-be
    // ══════════════════════════════════════════════════════════════════════

    public function test_unknown_top_level_key_is_not_persisted(): void
    {
        [$company, $user] = $this->makeCompanyWithUser();
        $this->asUser($user, $company);

        $payload = $this->payload();
        $payload['saved_filters'] = ['foo' => 'bar']; // nem támogatott kulcs, jövőbeli funkció helye

        $this->putJson('/api/list-preferences/invoices.index', $payload)->assertOk();

        $row = UserListPreference::withoutGlobalScope('company')->first();
        $this->assertArrayNotHasKey('saved_filters', $row->preferences);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 8: multi-company izoláció
    // ══════════════════════════════════════════════════════════════════════

    public function test_multi_company_isolation_via_header_switch(): void
    {
        $companyX = $this->makeCompany();
        $companyY = $this->makeCompany();
        $user = $this->makeUser();
        $user->companies()->attach([$companyX->id, $companyY->id]);

        $this->asUser($user, $companyX);
        $this->putJson('/api/list-preferences/invoices.index', $this->payload())->assertOk();

        $meX = $this->getJson('/api/me')->assertOk()->json();
        $this->assertArrayHasKey('invoices.index', $meX['list_preferences']);

        // átváltás Y cégre — ugyanaz a felhasználó, csak a fejléc/session vált
        $this->withSession(['current_company_id' => $companyY->id])
            ->withHeader('X-Company-Id', (string) $companyY->id);

        $meY = $this->getJson('/api/me')->assertOk()->json();
        $this->assertArrayNotHasKey('invoices.index', $meY['list_preferences']);

        $this->putJson('/api/list-preferences/invoices.index', $this->payload(['page_size' => 100]))->assertOk();

        $this->assertDatabaseCount('user_list_preferences', 2);
        $rowY = UserListPreference::withoutGlobalScope('company')->where('company_id', $companyY->id)->first();
        $this->assertSame(100, $rowY->preferences['page_size']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 9: user-izoláció ugyanannál a cégnél
    // ══════════════════════════════════════════════════════════════════════

    public function test_user_b_cannot_see_or_overwrite_user_a_row(): void
    {
        $company = $this->makeCompany();
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $userA->companies()->attach($company->id);
        $userB->companies()->attach($company->id);

        // A user sora közvetlen Eloquent-tel jön létre (nem HTTP-n át A-ként) —
        // a teszt-stack ismert korlátja miatt (l. BlameableTest), egy teszt-
        // metóduson belül két különböző actingAs()-szereplővel nem futtatható
        // egymást követő valódi HTTP-hívás.
        app(CurrentCompany::class)->set($company->id);
        UserListPreference::create([
            'user_id' => $userA->id,
            'list_key' => 'invoices.index',
            'preferences' => ['page_size' => 25],
        ]);

        $this->asUser($userB, $company);

        $me = $this->getJson('/api/me')->assertOk()->json();
        $this->assertArrayNotHasKey('invoices.index', $me['list_preferences']);

        $this->putJson('/api/list-preferences/invoices.index', $this->payload(['page_size' => 77]))->assertOk();

        $this->assertDatabaseCount('user_list_preferences', 2);
        $rowA = UserListPreference::withoutGlobalScope('company')->where('user_id', $userA->id)->first();
        $this->assertSame(25, $rowA->preferences['page_size']);
        $rowB = UserListPreference::withoutGlobalScope('company')->where('user_id', $userB->id)->first();
        $this->assertSame(77, $rowB->preferences['page_size']);
    }

    // ══════════════════════════════════════════════════════════════════════
    // 10: /api/me üres esetben {} , nem []
    // ══════════════════════════════════════════════════════════════════════

    public function test_me_returns_empty_object_when_no_preferences_exist(): void
    {
        [$company, $user] = $this->makeCompanyWithUser();
        $this->asUser($user, $company);

        $response = $this->getJson('/api/me')->assertOk();

        // json_decode(..., true) {}-t és []-t egyaránt üres PHP tömbbé alakítaná,
        // ezért objektum-módban (assoc=false) kell ellenőrizni a JSON-típust.
        $decoded = json_decode($response->getContent());
        $this->assertIsObject($decoded->list_preferences);
        $this->assertSame([], (array) $decoded->list_preferences);
    }

    // ══════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'columns' => [
                'visible' => ['number', 'partner', 'issue_date'],
                'order' => ['number', 'partner', 'issue_date'],
            ],
            'page_size' => 25,
            'sort' => ['by' => 'issue_date', 'dir' => 'desc'],
        ], $overrides);
    }

    private function makeCompany(): Company
    {
        self::$seq++;

        return Company::create([
            'name' => 'List Pref Company '.self::$seq,
            'tax_number' => '1234567'.self::$seq.'-2-04',
            'registration_number' => '01-01-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code' => '1111',
            'city' => 'Budapest',
            'address_line' => 'Teszt u. 1.',
            'country_code' => 'HU',
            'base_currency' => 'HUF',
        ]);
    }

    private function makeUser(): User
    {
        self::$seq++;

        return User::create([
            'name' => 'User '.self::$seq,
            'email' => 'list-pref-user'.self::$seq.'@example.com',
            'password' => bcrypt('password'),
            'is_superadmin' => false,
        ]);
    }

    /** @return array{0: Company, 1: User} */
    private function makeCompanyWithUser(): array
    {
        $company = $this->makeCompany();
        $user = $this->makeUser();
        $user->companies()->attach($company->id);

        return [$company, $user];
    }

    private function asUser(User $user, Company $company): static
    {
        return $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $company->id])
            ->withHeader('X-Company-Id', (string) $company->id);
    }
}
