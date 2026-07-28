<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Concerns\HasBlameable;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\Partner;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * HasBlameable trait (app/Models/Concerns/HasBlameable.php) — created_by/
 * updated_by kitöltés a törzsadat-táblákon (partners itt reprezentatívan,
 * a trait modellfüggetlen). l. docs/progress.md, docs/er-model.md.
 *
 * A 9. szakasz a mezők API-serializációját fedi: a blame-adat a DETAIL
 * válaszban jelenik meg felhasználónévvel és időponttal, a LISTÁBAN nem
 * (WithBlameable trait, app/Http/Resources/Concerns/WithBlameable.php).
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
    // 9: API-serializáció — a blame-adat a DETAIL válaszban, névvel és
    //    időponttal. Partner a reprezentatív modell; a minta (WithBlameable
    //    trait + HasBlameable::blameEntry) modellfüggetlen.
    // ══════════════════════════════════════════════════════════════════════════

    public function test_detail_endpoint_returns_creator_and_updater_name_with_timestamp(): void
    {
        $user = $this->makeUser();
        $this->attach($user);

        $created = $this->asUser($user)->postJson('/api/partners', $this->partnerPayload());
        $created->assertCreated();
        $partnerId = $created->json('data.id');

        $response = $this->asUser($user)->getJson("/api/partners/{$partnerId}");
        $response->assertOk();

        $this->assertSame($user->name, $response->json('data.created_by.name'));
        $this->assertSame($user->name, $response->json('data.updated_by.name'));

        // Az „at" a rekord created_at/updated_at értéke, ISO8601-ben — a
        // frontend ezt formázza majd, a backend nyers időbélyeget ad.
        $this->assertNotNull($response->json('data.created_by.at'));
        $this->assertSame(
            $response->json('data.created_at'),
            $response->json('data.created_by.at')
        );
        $this->assertSame(
            $response->json('data.updated_at'),
            $response->json('data.updated_by.at')
        );
    }

    public function test_detail_endpoint_returns_null_blame_for_row_written_without_actor(): void
    {
        // Auth-kontextus NÉLKÜL létrehozott sor (seeder / konzol / queue job
        // helyzet): a HasBlameable hookjai nem töltenek, a FK null marad.
        $partner = Partner::create($this->partnerPayload(['company_id' => $this->company->id]));
        $this->assertNull($partner->created_by);

        $viewer = $this->makeUser();
        $this->attach($viewer);

        $response = $this->asUser($viewer)->getJson("/api/partners/{$partner->id}");
        $response->assertOk();

        // Nem hiányzó kulcs és nem üres objektum: explicit null — ebből tudja a
        // frontend, hogy „Rendszer" fallbackot kell mutatnia.
        $this->assertArrayHasKey('created_by', $response->json('data'));
        $this->assertNull($response->json('data.created_by'));
        $this->assertNull($response->json('data.updated_by'));
    }

    public function test_blame_entry_returns_null_name_when_the_user_row_is_gone(): void
    {
        // Védekező ág: FK megvan, de a user sora már nincs. HTTP-n át ez a
        // jelenlegi sémával nem érhető el (a 2026_07_21_000001 migráció
        // nullOnDelete()-tel köti a FK-t, tehát user törlésekor a mező null
        // lesz), ezért közvetlenül a blameEntry()-t gyakoroljuk: betöltött, de
        // null reláció + kitöltött FK.
        $partner = new Partner;
        $partner->created_by = 999999;
        $partner->created_at = now();
        $partner->setRelation('creator', null);

        $entry = $partner->blameEntry('created_by', 'creator', 'created_at');

        $this->assertIsArray($entry);
        $this->assertNull($entry['name']);
        $this->assertNotNull($entry['at']);
    }

    public function test_list_endpoint_does_not_expose_blame_fields(): void
    {
        $user = $this->makeUser();
        $this->attach($user);

        $this->asUser($user)->postJson('/api/partners', $this->partnerPayload())->assertCreated();

        $response = $this->asUser($user)->getJson('/api/partners');
        $response->assertOk();

        // A listát ugyanaz a PartnerResource szolgálja ki, de a creator/updater
        // reláció nincs betöltve — a WithBlameable whenLoaded() kapuja miatt a
        // két kulcs teljesen kimarad. Ez a lista-oldali N+1 elleni védelem.
        $row = $response->json('data.0');
        $this->assertIsArray($row);
        $this->assertArrayNotHasKey('created_by', $row);
        $this->assertArrayNotHasKey('updated_by', $row);
    }

    public function test_detail_endpoint_eager_loads_blame_relations_without_n_plus_one(): void
    {
        $user = $this->makeUser();
        $this->attach($user);

        $created = $this->asUser($user)->postJson('/api/partners', $this->partnerPayload());
        $created->assertCreated();
        $partnerId = $created->json('data.id');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->asUser($user)->getJson("/api/partners/{$partnerId}")->assertOk();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        // Az oszlop-korlátozott eager-load pontosan 2 lekérdezést ad (creator +
        // updater), és a SELECT csak az id/name mezőt kéri. Lusta betöltésnél
        // ez a minta egyáltalán nem jelenne meg (az `select * from "users"`
        // alakot adná) — a pontos 2-es darabszám tehát egyszerre bizonyítja az
        // eager-loadot és az oszlop-korlátozást.
        $eagerLoads = collect($queries)
            ->filter(fn ($q) => str_contains($q['query'], 'select "id", "name" from "users"'))
            ->count();

        $this->assertSame(2, $eagerLoads);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 10: nyers modell-JSON-t adó detail-végpontok (nincs JsonResource) —
    //     GroupController::show és UserController::show. Itt a blame-adatot
    //     kézzel fésüljük a payloadba, ezért külön fedezet kell rá.
    // ══════════════════════════════════════════════════════════════════════════

    public function test_group_detail_endpoint_merges_blame_into_raw_model_json(): void
    {
        $actor = $this->makeUser();
        $this->attach($actor);
        $this->actingAs($actor);

        $group = Group::create(['company_id' => $this->company->id, 'name' => 'Teszt csoport']);
        $this->assertSame($actor->id, $group->created_by);

        $response = $this->asUser($actor)->getJson("/api/groups/{$group->id}");
        $response->assertOk();

        // A nyers JSON-ben a created_by/updated_by korábban egész FK volt — a
        // detail-válaszban a blame-objektum írja felül, hogy a szerződés
        // azonos legyen a Resource-alapú végpontokéval.
        $this->assertSame($actor->name, $response->json('created_by.name'));
        $this->assertSame($actor->name, $response->json('updated_by.name'));
        $this->assertNotNull($response->json('created_by.at'));

        // A betöltött relációk nem szivárognak be duplikátumként.
        $body = $response->json();
        $this->assertArrayNotHasKey('creator', $body);
        $this->assertArrayNotHasKey('updater', $body);

        // A payload többi része változatlan.
        $this->assertSame('Teszt csoport', $response->json('name'));
        $this->assertIsArray($response->json('permissions'));
        $this->assertIsArray($response->json('users'));
    }

    public function test_user_detail_endpoint_merges_blame_into_raw_model_json(): void
    {
        $actor = $this->makeUser();
        $this->attach($actor);
        $this->actingAs($actor);

        // Auth-kontextusban létrehozott user → a blame-mezők kitöltődnek.
        $target = $this->makeUser();
        $this->attach($target);
        $this->assertSame($actor->id, $target->created_by);

        $response = $this->asUser($actor)->getJson("/api/users/{$target->id}");
        $response->assertOk();

        $this->assertSame($actor->name, $response->json('user.created_by.name'));
        $this->assertSame($actor->name, $response->json('user.updated_by.name'));
        $this->assertNotNull($response->json('user.created_by.at'));

        $user = $response->json('user');
        $this->assertArrayNotHasKey('creator', $user);
        $this->assertArrayNotHasKey('updater', $user);

        // A show() többi ága érintetlen.
        $this->assertSame($target->name, $response->json('user.name'));
        $this->assertArrayHasKey('overrides', $response->json());
        $this->assertArrayHasKey('from_groups', $response->json());
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
