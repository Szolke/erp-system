<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\SalesGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regressziós teszt: az ÉRTÉKESÍTŐ csoport tagságok (sales_group_user) takarítása
 * MINDKÉT cég-leválasztási úton.
 *
 * Az eredeti hiba (Nyitott pontok #27): a pivot `cascadeOnDelete`-je csak a `users` sor
 * TÖRLÉSÉRE fut, a tipikus művelet viszont a company_user DETACH — ilyenkor a felhasználó
 * megmarad, csak a cég-tagsága szűnik meg, és bennragad az elhagyott cég értékesítő
 * csoportjaiban (árva sales_group_user sorok). Egyik útvonal sem nyúlt a salesGroups()-hoz:
 *   a) UserController::destroy()        — a felhasználó kivétele az AKTUÁLIS cégből
 *   b) UserCompanyController::detach()  — superadmin cég-leválasztás, cégek KÖZÖTT
 *
 * A javítás az RBAC-csoportokra már elvégzett fixek (`8e7ac1a`, `9233ba7`) szemantikai
 * másolata, és ugyanazt a két csapdát kerüli meg:
 * - a BelongsToMany::detach() a PIVOT táblán operál és a relációra rakott where-t némán
 *   eldobja → explicit id-lista nélkül MINDEN cég tagságát törölné (túl-detach);
 * - a SalesGroup BelongsToCompany globális scope-ja az AKTUÁLIS cégre szűr, a (b) úton
 *   viszont a leválasztott cég NEM feltétlenül az aktuális → withoutGlobalScope('company')
 *   nélkül a begyűjtő query semmit nem látna, és csendben nem törölnénk semmit (alul-detach).
 *
 * A (b) út tesztjei ezért szándékosan úgy futnak, hogy az AKTUÁLIS cég a B, a leválasztott
 * pedig az A — pontosan az az eset, ahol a scope-csapda él.
 */
class SalesGroupDetachScopeTest extends TestCase
{
    use RefreshDatabase;

    private Company    $companyA;
    private Company    $companyB;
    private Company    $companyC;
    private User       $superadmin;
    private User       $targetUser;
    private User       $otherUser;
    private SalesGroup $salesGroupA;
    private SalesGroup $salesGroupB;
    private SalesGroup $salesGroupC;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = $this->makeCompany('A Kft.', '11111111-1-11');
        $this->companyB = $this->makeCompany('B Kft.', '22222222-2-22');
        $this->companyC = $this->makeCompany('C Kft.', '33333333-3-33');

        // A superadmin MINDKÉT cég tagja: a (b) úton a B kontextusából dolgozik (miközben
        // az A-ról választ le), a (a) úton viszont az A cég az aktuális.
        $this->superadmin = User::create([
            'name'          => 'Superadmin',
            'email'         => 'superadmin@test.dev',
            'password'      => Hash::make('password'),
            'is_superadmin' => true,
            'is_active'     => true,
        ]);
        $this->superadmin->companies()->attach([$this->companyA->id, $this->companyB->id]);

        // A célfelhasználó mind a három cég tagja, és mindegyikben van értékesítő csoportja.
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

        $this->salesGroupA = SalesGroup::create(['company_id' => $this->companyA->id, 'name' => 'A-értékesítők']);
        $this->salesGroupB = SalesGroup::create(['company_id' => $this->companyB->id, 'name' => 'B-értékesítők']);
        $this->salesGroupC = SalesGroup::create(['company_id' => $this->companyC->id, 'name' => 'C-értékesítők']);

        $this->targetUser->salesGroups()->attach([
            $this->salesGroupA->id, $this->salesGroupB->id, $this->salesGroupC->id,
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
        $this->otherUser->salesGroups()->attach($this->salesGroupA->id);
    }

    // ── (a) UserController::destroy() — kivétel az AKTUÁLIS cégből ───────────────

    /**
     * Az "A" cégből való kivétel után az A-beli értékesítő csoport tagság ELTŰNIK,
     * a többi cégé viszont ÉRINTETLEN (a #27 hiba közvetlen cáfolata + túl-detach őr).
     */
    public function test_removing_user_from_company_removes_only_that_companys_sales_group_membership(): void
    {
        $this->asSuperadminInCompany($this->companyA)
            ->deleteJson("/api/users/{$this->targetUser->id}")
            ->assertNoContent();

        $this->assertFalse(
            $this->hasMembership($this->targetUser, $this->salesGroupA),
            'Az elhagyott cég (A) értékesítő csoport tagságának törlődnie kell — különben árva sales_group_user sor marad.'
        );
        $this->assertTrue(
            $this->hasMembership($this->targetUser, $this->salesGroupB),
            'A másik cég (B) tagsága NEM törlődhet: cross-company adatromlás.'
        );
        $this->assertTrue(
            $this->hasMembership($this->targetUser, $this->salesGroupC),
            'Egy harmadik cég (C) tagsága NEM törlődhet: cross-company adatromlás.'
        );
    }

    /**
     * Más felhasználók tagsága ugyanabban az A-csoportban megmarad.
     */
    public function test_removing_user_from_company_does_not_touch_other_users_sales_group_memberships(): void
    {
        $this->asSuperadminInCompany($this->companyA)
            ->deleteJson("/api/users/{$this->targetUser->id}")
            ->assertNoContent();

        $this->assertTrue(
            $this->hasMembership($this->otherUser, $this->salesGroupA),
            'Csak a kivett felhasználó tagsága szűnhet meg, más userek tagsága nem.'
        );
    }

    /**
     * Az elvesztett értékesítő csoport tagságok saját naplósort kapnak: a pivot sorok
     * nyom nélkül tűnnek el, ez az egyetlen visszaállítási forrás. Az akció-név
     * szándékosan KÜLÖNBÖZIK a user.groups_detached-tól (RBAC = jog, értékesítő
     * csoport = üzleti besorolás), a payload alakja viszont párhuzamos.
     */
    public function test_removing_user_from_company_audit_logs_detached_sales_groups(): void
    {
        $this->asSuperadminInCompany($this->companyA)
            ->deleteJson("/api/users/{$this->targetUser->id}")
            ->assertNoContent();

        $log = AuditLog::where('action', 'user.sales_groups_detached')
            ->where('company_id', $this->companyA->id)
            ->first();

        $this->assertNotNull($log, 'Az értékesítő csoport tagság elvesztésének naplósort kell kapnia.');
        $this->assertSame($this->targetUser->id, $log->auditable_id);
        $this->assertSame($this->companyA->id, $log->old_values['company_id']);
        $this->assertSame([$this->salesGroupA->id], $log->old_values['sales_group_ids']);
    }

    // ── (b) UserCompanyController::detach() — leválasztás CÉGEK KÖZÖTT ───────────

    /**
     * A leválasztott cég (A) tagsága eltűnik akkor is, ha az AKTUÁLIS cég a B.
     * withoutGlobalScope('company') nélkül a begyűjtő query az A csoportjait nem is
     * látná (alul-detach) — ez a teszt fogja meg.
     */
    public function test_detaching_company_removes_that_companys_sales_group_membership(): void
    {
        $this->asSuperadminInCompany($this->companyB)
            ->deleteJson("/api/users/{$this->targetUser->id}/companies/{$this->companyA->id}")
            ->assertNoContent();

        $this->assertFalse(
            $this->hasMembership($this->targetUser, $this->salesGroupA),
            'A leválasztott cég (A) értékesítő csoport tagságának törlődnie kell.'
        );
    }

    /**
     * A megtartott cégek (B = aktuális, C = harmadik) tagságai ÉRINTETLENEK — túl-detach őr.
     */
    public function test_detaching_company_keeps_sales_group_memberships_in_remaining_companies(): void
    {
        $this->asSuperadminInCompany($this->companyB)
            ->deleteJson("/api/users/{$this->targetUser->id}/companies/{$this->companyA->id}")
            ->assertNoContent();

        $this->assertTrue(
            $this->hasMembership($this->targetUser, $this->salesGroupB),
            'Az aktuális cég (B) tagsága NEM törlődhet: cross-company adatromlás.'
        );
        $this->assertTrue(
            $this->hasMembership($this->targetUser, $this->salesGroupC),
            'Egy harmadik cég (C) tagsága NEM törlődhet: cross-company adatromlás.'
        );
    }

    /**
     * Más felhasználók tagsága a leválasztott cég ugyanazon csoportjában megmarad.
     */
    public function test_detaching_company_does_not_touch_other_users_sales_group_memberships(): void
    {
        $this->asSuperadminInCompany($this->companyB)
            ->deleteJson("/api/users/{$this->targetUser->id}/companies/{$this->companyA->id}")
            ->assertNoContent();

        $this->assertTrue(
            $this->hasMembership($this->otherUser, $this->salesGroupA),
            'Csak a leválasztott felhasználó tagsága szűnhet meg, más userek tagsága nem.'
        );
    }

    /**
     * Ha az aktuális cég EGYBEN a leválasztott cég is, ugyanaz a helyes viselkedés.
     */
    public function test_detaching_the_current_company_also_removes_its_sales_group_membership(): void
    {
        $this->asSuperadminInCompany($this->companyA)
            ->deleteJson("/api/users/{$this->targetUser->id}/companies/{$this->companyA->id}")
            ->assertNoContent();

        $this->assertFalse($this->hasMembership($this->targetUser, $this->salesGroupA));
        $this->assertTrue($this->hasMembership($this->targetUser, $this->salesGroupB));
        $this->assertTrue($this->hasMembership($this->targetUser, $this->salesGroupC));
    }

    public function test_detaching_company_audit_logs_detached_sales_groups(): void
    {
        $this->asSuperadminInCompany($this->companyB)
            ->deleteJson("/api/users/{$this->targetUser->id}/companies/{$this->companyA->id}")
            ->assertNoContent();

        $log = AuditLog::where('action', 'user.sales_groups_detached')
            ->where('company_id', $this->companyA->id)
            ->first();

        $this->assertNotNull($log, 'Az értékesítő csoport tagság elvesztésének naplósort kell kapnia.');
        $this->assertSame($this->targetUser->id, $log->auditable_id);
        $this->assertSame($this->companyA->id, $log->old_values['company_id']);
        $this->assertSame([$this->salesGroupA->id], $log->old_values['sales_group_ids']);
    }

    /**
     * Tagság hiányában nem születik üres naplósor.
     */
    public function test_no_audit_row_when_there_was_no_sales_group_membership(): void
    {
        $this->targetUser->salesGroups()->detach($this->salesGroupA->id);

        $this->asSuperadminInCompany($this->companyB)
            ->deleteJson("/api/users/{$this->targetUser->id}/companies/{$this->companyA->id}")
            ->assertNoContent();

        $this->assertFalse(
            AuditLog::where('action', 'user.sales_groups_detached')->exists(),
            'Tagság hiányában nem születhet user.sales_groups_detached naplósor.'
        );
    }

    /**
     * ŐRSZEM az ÜRES id-lista ágra: a leválasztott cégben (A) NINCS tagság, máshol (B, C) VAN.
     *
     * A kód ma biztonságos — a begyűjtő query `pluck()->all()`-ja üres esetben `[]`-t ad, és a
     * `detach([])` a parseIds() korai return-je miatt garantált no-op. A veszély egy JÖVŐBELI
     * refaktor, ami az üres esetet argumentum nélküli (vagy null-os) detach()-re ejti vissza:
     * a `detach()` / `detach(null)` kihagyja a whereIn-t, és a parenthez tartozó ÖSSZES pivot
     * sort törli — azaz némán letépné a user B és C cégbeli tagságát is.
     *
     * A fenti test_no_audit_row_when_there_was_no_sales_group_membership ezt NEM fogja meg:
     * egy ilyen regressziónál a $salesGroupIds továbbra is `[]` marad, tehát az audit-guard
     * miatt naplósor sem születik — a teszt zöld maradna a totális törlés mellett is. Ezért
     * a kulcs itt a TAGSÁG-TÚLÉLÉS állítása; az audit-assert csak azt zárja ki, hogy egy
     * „mindent letépett, de mégis logolt" változat átcsússzon.
     */
    public function test_detaching_company_with_no_membership_there_leaves_other_memberships_intact(): void
    {
        // A user a leválasztandó A cég egyetlen értékesítő csoportjának sem tagja,
        // de B-ben és C-ben igen → a begyűjtő query üres listát ad vissza.
        $this->targetUser->salesGroups()->detach($this->salesGroupA->id);

        $this->asSuperadminInCompany($this->companyB)
            ->deleteJson("/api/users/{$this->targetUser->id}/companies/{$this->companyA->id}")
            ->assertNoContent();

        $this->assertTrue(
            $this->hasMembership($this->targetUser, $this->salesGroupB),
            'Üres id-listánál a detach() no-op kell legyen: a B cég tagsága NEM tűnhet el.'
        );
        $this->assertTrue(
            $this->hasMembership($this->targetUser, $this->salesGroupC),
            'Üres id-listánál a detach() no-op kell legyen: a C cég tagsága NEM tűnhet el.'
        );

        // Más felhasználó tagsága a leválasztott cég csoportjában szintén érintetlen.
        $this->assertTrue(
            $this->hasMembership($this->otherUser, $this->salesGroupA),
            'Üres id-listánál más felhasználó tagsága sem érintődhet.'
        );

        $this->assertFalse(
            AuditLog::where('action', 'user.sales_groups_detached')->exists(),
            'Ténylegesen semmi nem törlődött, ezért naplósor sem születhet.'
        );
    }

    // ── Segédmetódusok ──────────────────────────────────────────────────────────

    private function asSuperadminInCompany(Company $company): static
    {
        return $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $company->id])
            ->withHeader('X-Company-Id', (string) $company->id);
    }

    private function hasMembership(User $user, SalesGroup $salesGroup): bool
    {
        return DB::table('sales_group_user')
            ->where('user_id', $user->id)
            ->where('sales_group_id', $salesGroup->id)
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
