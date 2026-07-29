<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regressziós teszt: a UserController::destroy() cégből-kivétel útvonala csak az
 * ELHAGYOTT cég RBAC-csoport-tagságait törölheti, a többi cégét NEM.
 *
 * Az eredeti hiba: a `$user->groups()->whereHas('company', ...)->detach()` minta a
 * BelongsToMany::detach()-en keresztül a PIVOT táblán (user_group) operált, és a
 * relációra rakott whereHas-t figyelmen kívül hagyta → minden cég tagsága törlődött.
 */
class UserDestroyGroupDetachScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;
    private Company $companyB;
    private User    $superadmin;
    private User    $targetUser;
    private Group   $groupA;
    private Group   $groupB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = $this->makeCompany('A Kft.', '11111111-1-11');
        $this->companyB = $this->makeCompany('B Kft.', '22222222-2-22');

        $this->superadmin = User::create([
            'name'          => 'Superadmin',
            'email'         => 'superadmin@test.dev',
            'password'      => Hash::make('password'),
            'is_superadmin' => true,
            'is_active'     => true,
        ]);
        $this->superadmin->companies()->attach($this->companyA->id);

        // A célfelhasználó MINDKÉT cég tagja, és mindkettőben van RBAC-csoportja.
        $this->targetUser = User::create([
            'name'          => 'Teszt User',
            'email'         => 'user@test.dev',
            'password'      => Hash::make('password'),
            'is_superadmin' => false,
            'is_active'     => true,
        ]);
        $this->targetUser->companies()->attach([$this->companyA->id, $this->companyB->id]);

        $this->groupA = Group::create(['company_id' => $this->companyA->id, 'name' => 'A-csoport']);
        $this->groupB = Group::create(['company_id' => $this->companyB->id, 'name' => 'B-csoport']);

        $this->targetUser->groups()->attach([$this->groupA->id, $this->groupB->id]);
    }

    /**
     * Az "A" cégből való kivétel után a "B" cégbeli csoport-tagságnak MEG KELL MARADNIA.
     */
    public function test_removing_user_from_one_company_keeps_group_membership_in_other_companies(): void
    {
        $res = $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->companyA->id])
            ->withHeader('X-Company-Id', (string) $this->companyA->id)
            ->deleteJson("/api/users/{$this->targetUser->id}");

        $res->assertNoContent();

        // Az elhagyott cég tagsága ELTŰNIK — ez a helyes viselkedés.
        $this->assertFalse(
            DB::table('user_group')
                ->where('user_id', $this->targetUser->id)
                ->where('group_id', $this->groupA->id)
                ->exists(),
            'Az elhagyott cég (A) csoport-tagságának törlődnie kell.'
        );

        // A MÁSIK cég tagsága ÉRINTETLEN marad — ezt bizonyítja/cáfolja a teszt.
        $this->assertTrue(
            DB::table('user_group')
                ->where('user_id', $this->targetUser->id)
                ->where('group_id', $this->groupB->id)
                ->exists(),
            'A másik cég (B) csoport-tagsága NEM törlődhet: cross-company adatromlás.'
        );
    }

    /**
     * Az elvesztett csoport-tagságok naplósort kapnak — ez az egyetlen forrás,
     * amiből egy téves kivétel után visszaállítható a tagság.
     */
    public function test_detached_group_memberships_are_audit_logged(): void
    {
        $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->companyA->id])
            ->withHeader('X-Company-Id', (string) $this->companyA->id)
            ->deleteJson("/api/users/{$this->targetUser->id}")
            ->assertNoContent();

        $log = AuditLog::where('action', 'user.groups_detached')
            ->where('company_id', $this->companyA->id)
            ->first();

        $this->assertNotNull($log, 'A csoport-tagság elvesztésének naplósort kell kapnia.');
        $this->assertSame($this->companyA->id, $log->old_values['company_id']);
        $this->assertSame([$this->groupA->id], $log->old_values['group_ids']);
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
