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
 * Regressziós teszt: a UserCompanyController::detach() (superadmin cég-leválasztás,
 * DELETE /api/users/{user}/companies/{company}) a LEVÁLASZTOTT cég RBAC-csoport-tagságait
 * is szüntesse meg — de kizárólag azokat.
 *
 * Az eredeti hiba (Nyitott pontok #28, a `8e7ac1a` fix INVERZE): a végpont csak a
 * companies() detach-ot, a default_company_id-t és az audit-sort kezelte, a groups()-hoz
 * egyáltalán nem nyúlt → a user bennragadt a leválasztott cég csoportjaiban (árva
 * user_group sorok, ALUL-detach).
 *
 * A javítás két csapdát kerül meg egyszerre, ezért a tesztek szándékosan úgy futnak, hogy
 * az AKTUÁLIS cég NEM a leválasztott cég (ez a végpont superadmin-only, cégek között
 * dolgozik):
 * - withoutGlobalScope('company') nélkül a begyűjtő query a leválasztott cég csoportjait
 *   nem is látná (a Group scope az aktuális cégre szűr) → csendben nem törölnénk semmit;
 * - explicit id-lista nélkül a BelongsToMany::detach() a pivoton dolgozva MINDEN cég
 *   tagságát törölné (ez a `8e7ac1a`-ban javított túl-detach).
 */
class UserCompanyDetachGroupScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company $companyA;
    private Company $companyB;
    private Company $companyC;
    private User    $superadmin;
    private User    $targetUser;
    private User    $otherUser;
    private Group   $groupA;
    private Group   $groupB;
    private Group   $groupC;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = $this->makeCompany('A Kft.', '11111111-1-11');
        $this->companyB = $this->makeCompany('B Kft.', '22222222-2-22');
        $this->companyC = $this->makeCompany('C Kft.', '33333333-3-33');

        // A superadmin a B cég tagja: így tud a B cég kontextusában dolgozni, miközben
        // az A cégről választ le — pontosan ez a cross-company eset, ahol a Group
        // globális scope-ja félrevezetné a begyűjtő lekérdezést.
        $this->superadmin = User::create([
            'name'          => 'Superadmin',
            'email'         => 'superadmin@test.dev',
            'password'      => Hash::make('password'),
            'is_superadmin' => true,
            'is_active'     => true,
        ]);
        $this->superadmin->companies()->attach($this->companyB->id);

        // A célfelhasználó mind a három cég tagja, és mindegyikben van RBAC-csoportja.
        $this->targetUser = User::create([
            'name'          => 'Teszt User',
            'email'         => 'user@test.dev',
            'password'      => Hash::make('password'),
            'is_superadmin' => false,
            'is_active'     => true,
        ]);
        $this->targetUser->companies()->attach([
            $this->companyA->id, $this->companyB->id, $this->companyC->id,
        ]);

        $this->groupA = Group::create(['company_id' => $this->companyA->id, 'name' => 'A-csoport']);
        $this->groupB = Group::create(['company_id' => $this->companyB->id, 'name' => 'B-csoport']);
        $this->groupC = Group::create(['company_id' => $this->companyC->id, 'name' => 'C-csoport']);

        $this->targetUser->groups()->attach([
            $this->groupA->id, $this->groupB->id, $this->groupC->id,
        ]);

        // Egy másik user ugyanabban az A-csoportban: a pivot-törlés a szülő kulcsára
        // (user_id) is szorítva legyen, ne csak a csoport-id-kre.
        $this->otherUser = User::create([
            'name'          => 'Másik User',
            'email'         => 'other@test.dev',
            'password'      => Hash::make('password'),
            'is_superadmin' => false,
            'is_active'     => true,
        ]);
        $this->otherUser->companies()->attach($this->companyA->id);
        $this->otherUser->groups()->attach($this->groupA->id);
    }

    /**
     * A leválasztott cég (A) csoport-tagsága eltűnik, akkor is, ha az aktuális cég a B.
     * Ez a #28 hiba közvetlen cáfolata: a javítás előtt a sor bennmaradt.
     */
    public function test_detaching_company_removes_that_companys_group_memberships(): void
    {
        $this->asSuperadminInCompanyB()
            ->deleteJson("/api/users/{$this->targetUser->id}/companies/{$this->companyA->id}")
            ->assertNoContent();

        $this->assertFalse(
            $this->hasMembership($this->targetUser, $this->groupA),
            'A leválasztott cég (A) csoport-tagságának törlődnie kell — különben árva user_group sor marad.'
        );
    }

    /**
     * A megtartott cégek (B = aktuális, C = harmadik) tagságai ÉRINTETLENEK.
     * Ez a `8e7ac1a`-ban javított túl-detach elleni őr ezen az útvonalon.
     */
    public function test_detaching_company_keeps_group_memberships_in_remaining_companies(): void
    {
        $this->asSuperadminInCompanyB()
            ->deleteJson("/api/users/{$this->targetUser->id}/companies/{$this->companyA->id}")
            ->assertNoContent();

        $this->assertTrue(
            $this->hasMembership($this->targetUser, $this->groupB),
            'Az aktuális cég (B) csoport-tagsága NEM törlődhet: cross-company adatromlás.'
        );
        $this->assertTrue(
            $this->hasMembership($this->targetUser, $this->groupC),
            'Egy harmadik cég (C) csoport-tagsága NEM törlődhet: cross-company adatromlás.'
        );
    }

    /**
     * Más felhasználók tagsága a leválasztott cég ugyanazon csoportjában megmarad.
     */
    public function test_detaching_company_does_not_touch_other_users_memberships(): void
    {
        $this->asSuperadminInCompanyB()
            ->deleteJson("/api/users/{$this->targetUser->id}/companies/{$this->companyA->id}")
            ->assertNoContent();

        $this->assertTrue(
            $this->hasMembership($this->otherUser, $this->groupA),
            'Csak a leválasztott felhasználó tagsága szűnhet meg, más userek tagsága nem.'
        );
    }

    /**
     * Ha az aktuális cég EGYBEN a leválasztott cég is, ugyanaz a helyes viselkedés.
     */
    public function test_detaching_the_current_company_also_removes_its_group_memberships(): void
    {
        $this->superadmin->companies()->attach($this->companyA->id);

        $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->companyA->id])
            ->withHeader('X-Company-Id', (string) $this->companyA->id)
            ->deleteJson("/api/users/{$this->targetUser->id}/companies/{$this->companyA->id}")
            ->assertNoContent();

        $this->assertFalse($this->hasMembership($this->targetUser, $this->groupA));
        $this->assertTrue($this->hasMembership($this->targetUser, $this->groupB));
        $this->assertTrue($this->hasMembership($this->targetUser, $this->groupC));
    }

    /**
     * Az elvesztett csoport-tagságok naplósort kapnak, a UserController::destroy()
     * útvonalával azonos akció-névvel és payload-alakkal — a pivot sorok nyom nélkül
     * tűnnek el, ez az egyetlen visszaállítási forrás.
     */
    public function test_detached_group_memberships_are_audit_logged(): void
    {
        $this->asSuperadminInCompanyB()
            ->deleteJson("/api/users/{$this->targetUser->id}/companies/{$this->companyA->id}")
            ->assertNoContent();

        $log = AuditLog::where('action', 'user.groups_detached')
            ->where('company_id', $this->companyA->id)
            ->first();

        $this->assertNotNull($log, 'A csoport-tagság elvesztésének naplósort kell kapnia.');
        $this->assertSame($this->targetUser->id, $log->auditable_id);
        $this->assertSame($this->companyA->id, $log->old_values['company_id']);
        $this->assertSame([$this->groupA->id], $log->old_values['group_ids']);
    }

    /**
     * Ha a leválasztott cégben nem volt csoport-tagság, nem keletkezik üres naplósor.
     */
    public function test_no_audit_row_when_there_was_no_group_membership(): void
    {
        $this->targetUser->groups()->detach($this->groupA->id);

        $this->asSuperadminInCompanyB()
            ->deleteJson("/api/users/{$this->targetUser->id}/companies/{$this->companyA->id}")
            ->assertNoContent();

        $this->assertFalse(
            AuditLog::where('action', 'user.groups_detached')->exists(),
            'Tagság hiányában nem születhet user.groups_detached naplósor.'
        );
    }

    // ── Segédmetódusok ──────────────────────────────────────────────────────────

    private function asSuperadminInCompanyB(): static
    {
        return $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->companyB->id])
            ->withHeader('X-Company-Id', (string) $this->companyB->id);
    }

    private function hasMembership(User $user, Group $group): bool
    {
        return DB::table('user_group')
            ->where('user_id', $user->id)
            ->where('group_id', $group->id)
            ->exists();
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
