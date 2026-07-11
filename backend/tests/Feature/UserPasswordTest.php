<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * PUT /api/users/{user}/password — jelszócsere végpont tesztjei.
 *
 * Jogosultság:
 *   - Saját jelszó: bárki módosíthatja (superadmin is).
 *   - Más user jelszava: csak superadmin, ÉS a céluser nem lehet superadmin.
 *   - Normál user → más user jelszava: 403.
 *   - Superadmin → másik superadmin jelszava: 422.
 */
class UserPasswordTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private User    $superadmin;
    private User    $normalUser;
    private User    $otherUser;
    private User    $otherSuperadmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name'                => 'Jelszó Teszt Kft.',
            'tax_number'          => '55555555-5-55',
            'registration_number' => '01-09-333333',
            'postal_code'         => '1000',
            'city'                => 'Budapest',
            'address_line'        => 'Jelszó utca 1.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
        ]);

        $this->superadmin = User::create([
            'name'               => 'Superadmin',
            'email'              => 'super@test.dev',
            'password'           => Hash::make('secret'),
            'is_superadmin'      => true,
            'default_company_id' => $this->company->id,
        ]);
        $this->company->users()->attach($this->superadmin->id);

        $this->normalUser = User::create([
            'name'               => 'Normal User',
            'email'              => 'normal@test.dev',
            'password'           => Hash::make('secret'),
            'default_company_id' => $this->company->id,
        ]);
        $this->company->users()->attach($this->normalUser->id);

        $this->otherUser = User::create([
            'name'               => 'Other User',
            'email'              => 'other@test.dev',
            'password'           => Hash::make('secret'),
            'default_company_id' => $this->company->id,
        ]);
        $this->company->users()->attach($this->otherUser->id);

        $this->otherSuperadmin = User::create([
            'name'               => 'Other Superadmin',
            'email'              => 'super2@test.dev',
            'password'           => Hash::make('secret'),
            'is_superadmin'      => true,
            'default_company_id' => $this->company->id,
        ]);
        $this->company->users()->attach($this->otherSuperadmin->id);
    }

    public function test_normal_user_can_change_own_password(): void
    {
        $res = $this->actingAs($this->normalUser)
            ->putJson("/api/users/{$this->normalUser->id}/password", [
                'password' => 'newpassword123',
            ]);

        $res->assertNoContent();
        $this->assertTrue(Hash::check('newpassword123', $this->normalUser->fresh()->password));
    }

    public function test_superadmin_can_change_normal_user_password(): void
    {
        $res = $this->actingAs($this->superadmin)
            ->putJson("/api/users/{$this->normalUser->id}/password", [
                'password' => 'adminsetpass99',
            ]);

        $res->assertNoContent();
        $this->assertTrue(Hash::check('adminsetpass99', $this->normalUser->fresh()->password));
    }

    public function test_superadmin_can_change_own_password(): void
    {
        $res = $this->actingAs($this->superadmin)
            ->putJson("/api/users/{$this->superadmin->id}/password", [
                'password' => 'superNewPass1',
            ]);

        $res->assertNoContent();
        $this->assertTrue(Hash::check('superNewPass1', $this->superadmin->fresh()->password));
    }

    public function test_normal_user_cannot_change_other_users_password(): void
    {
        $res = $this->actingAs($this->normalUser)
            ->putJson("/api/users/{$this->otherUser->id}/password", [
                'password' => 'hacked123456',
            ]);

        $res->assertForbidden();
        $this->assertTrue(Hash::check('secret', $this->otherUser->fresh()->password));
    }

    public function test_superadmin_cannot_change_another_superadmin_password(): void
    {
        $res = $this->actingAs($this->superadmin)
            ->putJson("/api/users/{$this->otherSuperadmin->id}/password", [
                'password' => 'overrideSuper1',
            ]);

        $res->assertUnprocessable();
        $this->assertTrue(Hash::check('secret', $this->otherSuperadmin->fresh()->password));
    }

    public function test_password_too_short_returns_validation_error(): void
    {
        $res = $this->actingAs($this->normalUser)
            ->putJson("/api/users/{$this->normalUser->id}/password", [
                'password' => 'short',
            ]);

        $res->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }

    public function test_missing_password_returns_validation_error(): void
    {
        $res = $this->actingAs($this->normalUser)
            ->putJson("/api/users/{$this->normalUser->id}/password", []);

        $res->assertUnprocessable()
            ->assertJsonValidationErrors(['password']);
    }
}
