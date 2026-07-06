<?php

namespace Tests\Feature;

use App\Enums\CompanySetting;
use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CompanySetting::SIDEBAR_ACCENT_COLOR tesztek.
 *
 *  1. Default érték #1e293b (az alap slate szín), ha nincs DB-bejegyzés.
 *  2. Érvényes paletta-szín menthető és visszaolvasható.
 *  3. Érvénytelen szín (nem palettából való) → 422.
 *  4. Reset (DELETE) → ismét a default értéket adja vissza.
 */
class SidebarAccentColorTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private Company $company;
    private User    $user;

    protected function setUp(): void
    {
        parent::setUp();

        self::$seq++;

        $this->company = Company::create([
            'name'                => 'Accent Teszt Kft. ' . self::$seq,
            'tax_number'          => '7777777' . self::$seq . '-2-41',
            'registration_number' => '01-09-' . str_pad(900 + self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '1000',
            'city'                => 'Budapest',
            'address_line'        => 'Szín u. 1.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
        ]);

        $this->user = User::create([
            'name'          => 'Accent Tesztelő ' . self::$seq,
            'email'         => 'accent.test.' . self::$seq . '@example.com',
            'password'      => bcrypt('password'),
            'is_superadmin' => true,
            'is_active'     => true,
        ]);

        $this->user->companies()->attach($this->company->id, ['is_default' => true]);
        app(CurrentCompany::class)->set($this->company->id);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ── 1. Default ────────────────────────────────────────────────────────

    public function test_default_accent_color_is_slate(): void
    {
        $response = $this->inCompany()->getJson('/api/company/settings');

        $response->assertOk();

        $setting = collect($response->json('data'))
            ->firstWhere('key', 'sidebar_accent_color');

        $this->assertNotNull($setting);
        $this->assertSame('#1e293b', $setting['value']);
        $this->assertSame('#1e293b', $setting['default']);
    }

    // ── 2. Mentés + visszaolvasás ─────────────────────────────────────────

    public function test_valid_palette_color_can_be_saved_and_retrieved(): void
    {
        $response = $this->inCompany()->putJson('/api/company/settings/sidebar_accent_color', [
            'value' => '#134e4a',
        ]);

        $response->assertOk();
        $this->assertSame('#134e4a', $response->json('data.value'));

        // Visszaolvasás a GET /settings végponton
        $getResponse = $this->inCompany()->getJson('/api/company/settings');
        $setting = collect($getResponse->json('data'))
            ->firstWhere('key', 'sidebar_accent_color');

        $this->assertSame('#134e4a', $setting['value']);
    }

    // ── 3. Érvénytelen szín → 422 ─────────────────────────────────────────

    public function test_color_outside_palette_returns_422(): void
    {
        $this->inCompany()
            ->putJson('/api/company/settings/sidebar_accent_color', ['value' => '#ffff00'])
            ->assertStatus(422);

        $this->inCompany()
            ->putJson('/api/company/settings/sidebar_accent_color', ['value' => 'red'])
            ->assertStatus(422);
    }

    // ── 4. Reset → default ────────────────────────────────────────────────

    public function test_reset_restores_default_accent_color(): void
    {
        // Előbb elmentsük a teal-t
        $this->inCompany()->putJson('/api/company/settings/sidebar_accent_color', [
            'value' => '#134e4a',
        ])->assertOk();

        // Reset
        $this->inCompany()->deleteJson('/api/company/settings/sidebar_accent_color')
            ->assertOk();

        // Default vissza
        $getResponse = $this->inCompany()->getJson('/api/company/settings');
        $setting = collect($getResponse->json('data'))
            ->firstWhere('key', 'sidebar_accent_color');

        $this->assertSame('#1e293b', $setting['value']);
    }

    // ── 5. Mind a 18 paletta-szín átmegy a validate()-on ─────────────────

    public function test_all_eighteen_palette_colors_pass_validation(): void
    {
        $setting = CompanySetting::SIDEBAR_ACCENT_COLOR;

        foreach (CompanySetting::SIDEBAR_ACCENT_PALETTE as $hex) {
            $this->assertTrue(
                $setting->validate($hex),
                "Expected {$hex} to be a valid palette color, but validate() returned false"
            );
        }
    }

    // ── 6. Paletta mérete pontosan 18 ─────────────────────────────────────

    public function test_palette_size_is_eighteen(): void
    {
        $this->assertCount(18, CompanySetting::SIDEBAR_ACCENT_PALETTE);
    }

    // ── 7. Új szín (nem volt az eredeti 7-ben) menthető az API-n ─────────

    public function test_new_palette_color_can_be_saved_via_api(): void
    {
        // #1d4ed8 (kék-700) az eredeti 7-ben nem szerepelt
        $response = $this->inCompany()->putJson('/api/company/settings/sidebar_accent_color', [
            'value' => '#1d4ed8',
        ]);

        $response->assertOk();
        $this->assertSame('#1d4ed8', $response->json('data.value'));
    }

    // ── Segédfüggvény ─────────────────────────────────────────────────────

    private function inCompany(): static
    {
        return $this->actingAs($this->user)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->company->id])
            ->withHeader('X-Company-Id', (string) $this->company->id);
    }
}
