<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Concerns\HasBlameable;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * HasBlameable trait (app/Models/Concerns/HasBlameable.php) — created_by/
 * updated_by kitöltés a törzsadat-táblákon (partners itt reprezentatívan,
 * a trait modellfüggetlen). l. docs/progress.md, docs/er-model.md.
 */
class BlameableTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();

        $this->company = $this->makeCompany();
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 1: HTTP-n keresztüli create — a valós vezérlő útvonalat (PartnerController::
    // store) gyakorolja.
    // ══════════════════════════════════════════════════════════════════════════

    public function test_create_sets_created_by_and_updated_by_to_acting_user(): void
    {
        $user = $this->makeUser();
        $this->attach($user);

        $response = $this->asUser($user)->postJson('/api/partners', $this->partnerPayload());
        $response->assertCreated();

        $partner = Partner::withoutGlobalScope('company')->findOrFail($response->json('data.id'));

        $this->assertSame($user->id, $partner->created_by);
        $this->assertSame($user->id, $partner->updated_by);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 2-3: több szereplős update — közvetlenül az Eloquent/Auth rétegen keresztül.
    //
    // Nem HTTP-n át: a projekt statefulApi() (Sanctum SPA) middleware-e ezen a
    // konkrét stacken NEM engedi meg, hogy egyetlen teszt-metóduson belül két
    // egymást követő valódi HTTP-hívás két KÜLÖNBÖZŐ actingAs()-szereplővel
    // fusson — a második hívás 401 "Unauthenticated"-et ad, mert a 'sanctum'
    // guard nem veszi fel az újonnan setUser()-özött usert a már lezajlott
    // kérés után (igazoltan: egy önálló diagnosztikai teszttel reprodukálva,
    // egy egyszerű /api/me GET-en is ugyanez történt — tehát nem a
    // HasBlameable vagy a Partner-végpont hibája, hanem a teszt-stack egy
    // ismert korlátja). A trait maga (Auth::hasUser()/Auth::id()) ugyanezt a
    // réteget használja, amit az Auth::login()/logout() páros itt közvetlenül
    // gyakorol — a lefedettség így egyenértékű, csak a HTTP-kerettől független.
    // ══════════════════════════════════════════════════════════════════════════

    public function test_update_changes_updated_by_but_not_created_by(): void
    {
        $creator = $this->makeUser();
        $editor = $this->makeUser();

        Auth::login($creator);
        $partner = Partner::create($this->partnerPayload(['company_id' => $this->company->id]));
        Auth::logout();

        Auth::login($editor);
        $partner->update(['name' => 'Módosított Partner']);
        Auth::logout();

        $partner->refresh();
        $this->assertSame($creator->id, $partner->created_by);
        $this->assertSame($editor->id, $partner->updated_by);
    }

    public function test_updated_by_always_follows_the_current_actor(): void
    {
        $creator = $this->makeUser();
        $second = $this->makeUser();
        $third = $this->makeUser();

        Auth::login($creator);
        $partner = Partner::create($this->partnerPayload(['company_id' => $this->company->id]));
        Auth::logout();

        Auth::login($second);
        $partner->update(['name' => 'Második módosítás']);
        Auth::logout();
        $partner->refresh();
        $this->assertSame($second->id, $partner->updated_by);
        $this->assertSame($creator->id, $partner->created_by);

        Auth::login($third);
        $partner->update(['name' => 'Harmadik módosítás']);
        Auth::logout();
        $partner->refresh();
        $this->assertSame($third->id, $partner->updated_by);
        $this->assertSame($creator->id, $partner->created_by);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 4-5: auth-kontextus nélküli írás — közvetlen model és console command
    // ══════════════════════════════════════════════════════════════════════════

    public function test_creating_without_auth_context_leaves_blame_fields_null(): void
    {
        app(CurrentCompany::class)->set($this->company->id);

        $partner = Partner::create($this->partnerPayload());

        $this->assertNull($partner->created_by);
        $this->assertNull($partner->updated_by);
    }

    public function test_console_command_creating_a_user_leaves_blame_fields_null(): void
    {
        $this->artisan('erp:create-superadmin')
            ->expectsQuestion('Email cím', 'cmd-superadmin@example.com')
            ->expectsQuestion('Felhasználónév', 'CMD Superadmin')
            ->expectsQuestion('Jelszó', 'password123')
            ->assertExitCode(0);

        $created = User::where('email', 'cmd-superadmin@example.com')->firstOrFail();

        $this->assertNull($created->created_by);
        $this->assertNull($created->updated_by);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 6-7: mass assignment védelem és explicit actor át nem írása
    // ══════════════════════════════════════════════════════════════════════════

    public function test_created_by_is_not_mass_assignable(): void
    {
        $user = $this->makeUser();
        $this->actingAs($user);

        $partner = Partner::create($this->partnerPayload([
            'company_id' => $this->company->id,
            'created_by' => 999999,
        ]));

        $this->assertNotSame(999999, $partner->created_by);
        $this->assertSame($user->id, $partner->created_by);
    }

    public function test_explicit_created_by_at_creation_is_not_overwritten(): void
    {
        $explicitActor = $this->makeUser();
        $actingUser = $this->makeUser();
        $this->actingAs($actingUser);

        $partner = new Partner($this->partnerPayload(['company_id' => $this->company->id]));
        $partner->created_by = $explicitActor->id;
        $partner->save();

        // created_by-t direkt attribútum-beállítással állítottuk (nem tömeges
        // hozzárendeléssel), ezért a "csak ha még null" guard nem írja felül.
        $this->assertSame($explicitActor->id, $partner->created_by);
        // updated_by ekkor még null volt, azt a creating hook a jelenlegi
        // auth userrel tölti — a guard mezőnként (nem együttesen) dönt.
        $this->assertSame($actingUser->id, $partner->updated_by);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 8: invoices/receipts/payments viselkedése változatlan (nincs HasBlameable)
    // ══════════════════════════════════════════════════════════════════════════

    public function test_invoice_blame_behavior_is_unchanged(): void
    {
        $this->assertNotContains(HasBlameable::class, class_uses(Invoice::class));

        $user = $this->makeUser();
        $this->actingAs($user);

        $invoice = new Invoice(['company_id' => $this->company->id]);

        // Az Invoice nem kapott HasBlameable traitet — a mezőt kizárólag az
        // InvoiceService tölti explicit módon (l. docs/progress.md), egy
        // auth-kontextusban lévő közvetlen model-létrehozás nem tölti ki.
        $this->assertNull($invoice->created_by);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════════

    private function partnerPayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'customer',
            'name' => 'Teszt Partner',
            'billing_postal_code' => '1010',
            'billing_city' => 'Budapest',
            'billing_address_line' => 'Fő utca 1.',
            'billing_country_code' => 'HU',
            'default_currency' => 'HUF',
        ], $overrides);
    }

    private function makeCompany(): Company
    {
        self::$seq++;

        return Company::create([
            'name'                => 'Company '.self::$seq,
            'tax_number'          => '1234567'.self::$seq.'-2-03',
            'registration_number' => '01-01-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '1111',
            'city'                => 'Budapest',
            'address_line'        => 'Teszt u. 1.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
        ]);
    }

    private function makeUser(): User
    {
        self::$seq++;

        return User::create([
            'name'          => 'User '.self::$seq,
            'email'         => 'user'.self::$seq.'@example.com',
            'password'      => bcrypt('password'),
            'is_superadmin' => true,
        ]);
    }

    private function attach(User $user): void
    {
        $user->companies()->attach($this->company->id, ['is_default' => true]);
    }

    private function asUser(User $user): static
    {
        return $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $this->company->id])
            ->withHeader('X-Company-Id', (string) $this->company->id);
    }
}
