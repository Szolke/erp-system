<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * POST /api/companies — cég létrehozása (csak superadmin).
 *
 * Regressziós teszt: a `companies` tábla korábban NOT NULL-ként definiálta a
 * registration_number/postal_code/city/address_line mezőket, miközben a
 * StoreCompanyRequest mind a négyet 'nullable'-nek jelölte — ez a kliens
 * (UI) számára egy nyers Postgres NOT NULL constraint hibaként (500) jelent
 * meg validációs 422 helyett, mihelyt e mezők bármelyike üresen maradt.
 * L. 2026_07_25_000001_make_company_address_fields_nullable.php.
 */
class CompanyStoreTest extends TestCase
{
    use RefreshDatabase;

    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->superadmin = User::create([
            'name' => 'Superadmin',
            'email' => 'superadmin@test.dev',
            'password' => Hash::make('password'),
            'is_superadmin' => true,
            'is_active' => true,
        ]);
    }

    public function test_store_with_only_required_fields_succeeds(): void
    {
        $res = $this->actingAs($this->superadmin)->postJson('/api/companies', [
            'name' => 'Minimál Kft.',
            'tax_number' => '11111111-1-11',
        ]);

        $res->assertCreated();
        $this->assertDatabaseHas('companies', [
            'name' => 'Minimál Kft.',
            'tax_number' => '11111111-1-11',
            'registration_number' => null,
            'postal_code' => null,
            'city' => null,
            'address_line' => null,
        ]);
    }

    public function test_store_with_all_fields_succeeds(): void
    {
        $res = $this->actingAs($this->superadmin)->postJson('/api/companies', [
            'name' => 'Teljes Kft.',
            'tax_number' => '22222222-2-22',
            'registration_number' => '01-09-000001',
            'postal_code' => '1000',
            'city' => 'Budapest',
            'address_line' => 'Teszt utca 1.',
        ]);

        $res->assertCreated();
        $this->assertDatabaseHas('companies', [
            'name' => 'Teljes Kft.',
            'registration_number' => '01-09-000001',
            'postal_code' => '1000',
            'city' => 'Budapest',
            'address_line' => 'Teszt utca 1.',
        ]);
    }

    public function test_store_without_name_returns_422(): void
    {
        $this->actingAs($this->superadmin)->postJson('/api/companies', [
            'tax_number' => '33333333-3-33',
        ])->assertUnprocessable();
    }

    public function test_non_superadmin_cannot_store(): void
    {
        $user = User::create([
            'name' => 'Normal',
            'email' => 'normal@test.dev',
            'password' => Hash::make('password'),
            'is_superadmin' => false,
        ]);

        $this->actingAs($user)->postJson('/api/companies', [
            'name' => 'Tiltott Kft.',
            'tax_number' => '44444444-4-44',
        ])->assertForbidden();
    }
}
