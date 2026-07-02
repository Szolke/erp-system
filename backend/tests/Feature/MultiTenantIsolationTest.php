<?php

namespace Tests\Feature;

use App\Enums\PartnerType;
use App\Models\Company;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Ellenőrzi, hogy a BelongsToCompany globális scope + EnsureCompanyContext middleware
 * ténylegesen elkülöníti a cégek adatait lista- és egyedi lekérésen is.
 *
 * Szuperadmin usert használunk, hogy az RBAC ne zavarjon bele az izolációs tesztelésbe.
 */
class MultiTenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;
    private Company $companyB;
    private User    $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = $this->makeCompany('A Kft.', '11111111-1-11');
        $this->companyB = $this->makeCompany('B Kft.', '22222222-2-22');

        $this->user = User::create([
            'name'          => 'Teszt Admin',
            'email'         => 'admin@test.dev',
            'password'      => Hash::make('password'),
            'is_superadmin' => true,
            'is_active'     => true,
        ]);

        // Felhasználó mindkét céghez tartozik
        $this->user->companies()->attach([
            $this->companyA->id => ['is_default' => true],
            $this->companyB->id => ['is_default' => false],
        ]);
    }

    // --- Partner lista izoláció ---

    public function test_partner_list_only_returns_own_company_records(): void
    {
        $this->createPartner($this->companyA, 'A-partner');
        $this->createPartner($this->companyA, 'A-partner-2');
        $this->createPartner($this->companyB, 'B-partner');

        $response = $this->inCompany($this->user, $this->companyA)
            ->getJson('/api/partners');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(2, $data);
        $names = array_column($data, 'name');
        $this->assertContains('A-partner', $names);
        $this->assertContains('A-partner-2', $names);
        $this->assertNotContains('B-partner', $names);
    }

    public function test_partner_list_in_company_b_context_excludes_company_a_records(): void
    {
        $this->createPartner($this->companyA, 'A-partner');
        $this->createPartner($this->companyB, 'B-partner');

        $response = $this->inCompany($this->user, $this->companyB)
            ->getJson('/api/partners');

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('B-partner', $data[0]['name']);
    }

    public function test_empty_list_when_company_has_no_partners(): void
    {
        $this->createPartner($this->companyA, 'A-partner');

        $response = $this->inCompany($this->user, $this->companyB)
            ->getJson('/api/partners');

        $response->assertOk();
        $this->assertCount(0, $response->json('data'));
    }

    // --- Partner egyedi lekérés (show) izoláció ---

    public function test_show_partner_accessible_in_own_company_context(): void
    {
        $partner = $this->createPartner($this->companyA, 'A-partner');

        $response = $this->inCompany($this->user, $this->companyA)
            ->getJson("/api/partners/{$partner->id}");

        $response->assertOk();
        $response->assertJsonPath('data.name', 'A-partner');
    }

    public function test_show_partner_returns_404_in_other_company_context(): void
    {
        // A cég partnerét B cég kontextusából kérjük le — a globális scope kizárja
        $partnerA = $this->createPartner($this->companyA, 'A-partner');

        $response = $this->inCompany($this->user, $this->companyB)
            ->getJson("/api/partners/{$partnerA->id}");

        $response->assertNotFound();
    }

    // --- Kereszt-cég ID hivatkozás ---

    public function test_cross_company_id_reference_is_rejected(): void
    {
        // Mindkét cégnél van partner, B próbálja elérni A rekordját ID alapján
        $partnerA = $this->createPartner($this->companyA, 'Titkos partner');
        $this->createPartner($this->companyB, 'B saját partner');

        $response = $this->inCompany($this->user, $this->companyB)
            ->getJson("/api/partners/{$partnerA->id}");

        // A globális scope kizárja A partnerét B kontextusában → 404, nem 403
        // (nem árul el a rekord létezéséről semmit)
        $response->assertNotFound();
    }

    // --- Model-szintű izoláció (közvetlen Eloquent, middleware nélkül) ---

    public function test_eloquent_scope_filters_by_current_company_singleton(): void
    {
        $this->createPartner($this->companyA, 'A-partner');
        $this->createPartner($this->companyB, 'B-partner');

        // CurrentCompany-t közvetlenül állítjuk, nem middleware-en keresztül
        app(\App\Support\CurrentCompany::class)->set($this->companyA->id);
        $partners = Partner::all();
        app(\App\Support\CurrentCompany::class)->clear();

        $this->assertCount(1, $partners);
        $this->assertSame('A-partner', $partners->first()->name);
    }

    public function test_withoutglobalscope_sees_all_companies(): void
    {
        $this->createPartner($this->companyA, 'A-partner');
        $this->createPartner($this->companyB, 'B-partner');

        $all = Partner::withoutGlobalScope('company')->get();

        $this->assertCount(2, $all);
    }

    // --- Nem tag felhasználó elutasítása ---

    public function test_user_cannot_access_company_they_dont_belong_to(): void
    {
        $companyC    = $this->makeCompany('C Kft.', '33333333-3-33');
        $outsiderUser = User::create([
            'name'          => 'Kívülálló',
            'email'         => 'outsider@test.dev',
            'password'      => Hash::make('password'),
            'is_superadmin' => true,
            'is_active'     => true,
        ]);
        // outsiderUser nincs a companyC-ben
        $outsiderUser->companies()->attach($this->companyA->id, ['is_default' => true]);

        $response = $this->inCompany($outsiderUser, $companyC)
            ->getJson('/api/partners');

        $response->assertForbidden();
    }

    // --- Segédmetódusok ---

    /**
     * Visszaad egy hívó objektumot, ahol a user be van jelentkezve és a megadott
     * cég kontextusa aktív. Az Origin: http://localhost header szükséges ahhoz,
     * hogy a Sanctum EnsureFrontendRequestsAreStateful middleware stateful-ként
     * kezelje a kérést, és így elindítsa a session store-t (amelyet az
     * EnsureCompanyContext middleware is használ a company ID tárolásához).
     */
    private function inCompany(User $user, Company $company): static
    {
        return $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $company->id])
            ->withHeader('X-Company-Id', (string) $company->id);
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

    private function createPartner(Company $company, string $name): Partner
    {
        return Partner::withoutGlobalScope('company')->create([
            'company_id'          => $company->id,
            'name'                => $name,
            'type'                => PartnerType::Customer->value,
            'billing_postal_code' => '1000',
            'billing_city'        => 'Budapest',
            'billing_address_line' => 'Teszt utca 1.',
            'default_currency'    => 'HUF',
        ]);
    }
}
