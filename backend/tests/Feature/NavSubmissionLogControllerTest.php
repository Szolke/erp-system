<?php

namespace Tests\Feature;

use App\Enums\DocumentType;
use App\Enums\InvoiceStatus;
use App\Enums\NavStatus;
use App\Enums\PaymentStatus;
use App\Models\Company;
use App\Models\DocumentSeries;
use App\Models\Group;
use App\Models\Invoice;
use App\Models\Module;
use App\Models\NavSubmissionLog;
use App\Models\Partner;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\User;
use App\Services\PermissionChecker;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * HTTP-szintű tesztek a NavSubmissionLogController három végpontjára (3. fázis,
 * l. docs/nav-logging-audit.md): számla-szintű előzmények, cégszintű lista,
 * részletező. Mindhárom `nav.log.view` joggal védett, ami szándékosan KÜLÖN
 * kulcs a meglévő `nav.view_log`-tól (Dashboard-widget-szintű, csak összesítő).
 */
class NavSubmissionLogControllerTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private Company $companyA;
    private Company $companyB;
    private User $superadmin;
    private Module $navModule;
    private Permission $permLogView;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();

        $this->companyA = $this->makeCompany();
        $this->companyB = $this->makeCompany();
        $this->superadmin = $this->makeUser(superadmin: true);
        $this->superadmin->companies()->attach([
            $this->companyA->id => ['is_default' => true],
            $this->companyB->id => ['is_default' => false],
        ]);

        $this->navModule = $this->makeModule('nav');
        $this->enableModule($this->companyA);
        $this->enableModule($this->companyB);

        $this->permLogView = Permission::create([
            'key' => 'nav.log.view', 'module' => 'nav',
            'description' => 'test', 'is_sensitive' => true,
        ]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 1. Jog nélkül mindhárom végpont 403
    // ══════════════════════════════════════════════════════════════════════════

    public function test_index_returns_403_without_nav_log_view_permission(): void
    {
        $user = $this->makeUser();
        $user->companies()->attach($this->companyA->id, ['is_default' => true]);

        $this->asUser($user, $this->companyA)->getJson('/api/nav-submissions')->assertForbidden();
    }

    public function test_for_invoice_returns_403_without_nav_log_view_permission(): void
    {
        $user = $this->makeUser();
        $user->companies()->attach($this->companyA->id, ['is_default' => true]);
        $invoice = $this->makeInvoice($this->companyA, NavStatus::Error);

        $this->asUser($user, $this->companyA)
            ->getJson("/api/invoices/{$invoice->id}/nav-submissions")
            ->assertForbidden();
    }

    public function test_show_returns_403_without_nav_log_view_permission(): void
    {
        $user = $this->makeUser();
        $user->companies()->attach($this->companyA->id, ['is_default' => true]);
        $invoice = $this->makeInvoice($this->companyA, NavStatus::Error);
        $log = $this->makeLog($invoice, ['status' => 'error']);

        $this->asUser($user, $this->companyA)
            ->getJson("/api/nav-submissions/{$log->id}")
            ->assertForbidden();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 2. Másik cég naplóbejegyzése nem érhető el, route model bindingen át sem
    // ══════════════════════════════════════════════════════════════════════════

    public function test_show_of_other_companys_log_returns_404(): void
    {
        $invoiceB = $this->makeInvoice($this->companyB, NavStatus::Error);
        $logB = $this->makeLog($invoiceB, ['status' => 'error']);

        // superadmin — nav.log.view mindenképp megvan neki, tisztán a cég-scope-ot teszteljük
        $this->asAdmin($this->companyA)
            ->getJson("/api/nav-submissions/{$logB->id}")
            ->assertNotFound();
    }

    public function test_for_invoice_of_other_company_returns_404(): void
    {
        $invoiceB = $this->makeInvoice($this->companyB, NavStatus::Error);

        $this->asAdmin($this->companyA)
            ->getJson("/api/invoices/{$invoiceB->id}/nav-submissions")
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 3-4. Lista/előzmény válaszok nyers XML nélkül, a részletező IGEN
    // ══════════════════════════════════════════════════════════════════════════

    public function test_index_response_never_contains_raw_xml(): void
    {
        $invoice = $this->makeInvoice($this->companyA, NavStatus::Error);
        $this->makeLog($invoice, [
            'status' => 'error',
            'request_xml' => '<InvoiceData>szenzitiv adat sosem ide</InvoiceData>',
            'response_xml' => 'transactionId: TEST',
        ]);

        $response = $this->asAdmin($this->companyA)->getJson('/api/nav-submissions?status=all');

        $response->assertOk();
        $row = $response->json('data.0');
        $this->assertArrayNotHasKey('request_xml', $row);
        $this->assertArrayNotHasKey('response_xml', $row);
    }

    public function test_for_invoice_response_never_contains_raw_xml(): void
    {
        $invoice = $this->makeInvoice($this->companyA, NavStatus::Error);
        $this->makeLog($invoice, [
            'status' => 'error',
            'request_xml' => '<InvoiceData>szenzitiv adat sosem ide</InvoiceData>',
            'response_xml' => 'transactionId: TEST',
        ]);

        $response = $this->asAdmin($this->companyA)
            ->getJson("/api/invoices/{$invoice->id}/nav-submissions");

        $response->assertOk();
        $row = $response->json('data.0');
        $this->assertArrayNotHasKey('request_xml', $row);
        $this->assertArrayNotHasKey('response_xml', $row);
    }

    public function test_show_response_contains_raw_xml(): void
    {
        $invoice = $this->makeInvoice($this->companyA, NavStatus::Error);
        $log = $this->makeLog($invoice, [
            'status' => 'error',
            'request_xml' => '<InvoiceData>xml-tartalom</InvoiceData>',
            'response_xml' => 'transactionId: TEST-XML',
        ]);

        $response = $this->asAdmin($this->companyA)->getJson("/api/nav-submissions/{$log->id}");

        $response->assertOk();
        $response->assertJsonPath('data.request_xml', '<InvoiceData>xml-tartalom</InvoiceData>');
        $response->assertJsonPath('data.response_xml', 'transactionId: TEST-XML');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 5. A hibás-szűrő ténylegesen csak a hibás/elutasított/beavatkozást igénylő
    //    számlák beküldéseit adja vissza — alapértelmezetten ÉS explicit ?status=
    // ══════════════════════════════════════════════════════════════════════════

    public function test_default_filter_returns_only_error_bucket_invoices(): void
    {
        $errorInvoice = $this->makeInvoice($this->companyA, NavStatus::Error);
        $rejectedInvoice = $this->makeInvoice($this->companyA, NavStatus::Rejected);
        $needsAttentionInvoice = $this->makeInvoice($this->companyA, NavStatus::NeedsAttention);
        $sentInvoice = $this->makeInvoice($this->companyA, NavStatus::Sent);
        $confirmedInvoice = $this->makeInvoice($this->companyA, NavStatus::Confirmed);

        $this->makeLog($errorInvoice, ['status' => 'error']);
        $this->makeLog($rejectedInvoice, ['status' => 'success', 'processing_result' => 'ABORTED']);
        $this->makeLog($needsAttentionInvoice, ['status' => 'success', 'processing_result' => 'PROCESSING']);
        $this->makeLog($sentInvoice, ['status' => 'success', 'processing_result' => 'PROCESSING']);
        $this->makeLog($confirmedInvoice, ['status' => 'success', 'processing_result' => 'DONE']);

        // Alapértelmezett — nincs ?status= paraméter
        $response = $this->asAdmin($this->companyA)->getJson('/api/nav-submissions');

        $response->assertOk();
        $invoiceIds = collect($response->json('data'))->pluck('invoice_id')->sort()->values()->all();
        $expected = collect([$errorInvoice->id, $rejectedInvoice->id, $needsAttentionInvoice->id])->sort()->values()->all();
        $this->assertSame($expected, $invoiceIds);
    }

    public function test_pending_filter_returns_only_sent_invoices(): void
    {
        $sentInvoice = $this->makeInvoice($this->companyA, NavStatus::Sent);
        $errorInvoice = $this->makeInvoice($this->companyA, NavStatus::Error);

        $this->makeLog($sentInvoice, ['status' => 'success', 'processing_result' => 'PROCESSING']);
        $this->makeLog($errorInvoice, ['status' => 'error']);

        $response = $this->asAdmin($this->companyA)->getJson('/api/nav-submissions?status=pending');

        $response->assertOk();
        $invoiceIds = collect($response->json('data'))->pluck('invoice_id')->all();
        $this->assertSame([$sentInvoice->id], $invoiceIds);
    }

    public function test_all_filter_returns_every_status(): void
    {
        $errorInvoice = $this->makeInvoice($this->companyA, NavStatus::Error);
        $confirmedInvoice = $this->makeInvoice($this->companyA, NavStatus::Confirmed);

        $this->makeLog($errorInvoice, ['status' => 'error']);
        $this->makeLog($confirmedInvoice, ['status' => 'success', 'processing_result' => 'DONE']);

        $response = $this->asAdmin($this->companyA)->getJson('/api/nav-submissions?status=all');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 6. Lapozás SZÁMLÁKAT lapoz, nem naplósorokat
    // ══════════════════════════════════════════════════════════════════════════

    public function test_index_paginates_invoices_not_log_rows(): void
    {
        for ($i = 1; $i <= 21; $i++) {
            $invoice = $this->makeInvoice($this->companyA, NavStatus::Error);
            $this->makeLog($invoice, ['status' => 'error']);
        }

        $response = $this->asAdmin($this->companyA)->getJson('/api/nav-submissions?per_page=20');

        $response->assertOk();
        $this->assertCount(20, $response->json('data'));
        $this->assertSame(21, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.last_page'));
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 6b. Számlánkénti csoportosítás — egy sor = egy érintett számla
    // ══════════════════════════════════════════════════════════════════════════

    public function test_three_failed_attempts_on_same_invoice_produce_one_row_with_attempt_count_three(): void
    {
        $invoice = $this->makeInvoice($this->companyA, NavStatus::Error);
        $this->makeLog($invoice, ['status' => 'error', 'created_at' => now()->subMinutes(10)]);
        $this->makeLog($invoice, ['status' => 'error', 'created_at' => now()->subMinutes(5)]);
        $this->makeLog($invoice, ['status' => 'error', 'created_at' => now()]);

        $response = $this->asAdmin($this->companyA)->getJson('/api/nav-submissions');

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame(3, $response->json('data.0.attempt_count'));
    }

    public function test_row_carries_the_latest_attempts_data_not_the_first(): void
    {
        $invoice = $this->makeInvoice($this->companyA, NavStatus::Error);
        $this->makeLog($invoice, [
            'status' => 'error', 'transaction_id' => 'TRX-FIRST',
            'error_message' => 'első hiba', 'created_at' => now()->subMinutes(10),
        ]);
        $latest = $this->makeLog($invoice, [
            'status' => 'error', 'transaction_id' => 'TRX-LATEST',
            'error_message' => 'legutolsó hiba', 'created_at' => now(),
        ]);

        $response = $this->asAdmin($this->companyA)->getJson('/api/nav-submissions');

        $response->assertOk();
        $this->assertSame($latest->id, $response->json('data.0.latest.id'));
        $this->assertSame('TRX-LATEST', $response->json('data.0.latest.transaction_id'));
    }

    public function test_two_different_invoices_produce_two_rows(): void
    {
        $invoiceA = $this->makeInvoice($this->companyA, NavStatus::Error);
        $invoiceB = $this->makeInvoice($this->companyA, NavStatus::Rejected);
        $this->makeLog($invoiceA, ['status' => 'error']);
        $this->makeLog($invoiceA, ['status' => 'error']);
        $this->makeLog($invoiceB, ['status' => 'success', 'processing_result' => 'ABORTED']);

        $response = $this->asAdmin($this->companyA)->getJson('/api/nav-submissions');

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
        $invoiceIds = collect($response->json('data'))->pluck('invoice_id')->sort()->values()->all();
        $this->assertSame(collect([$invoiceA->id, $invoiceB->id])->sort()->values()->all(), $invoiceIds);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // 7. nav.log.view mindkét feloldási úton — Gate ÉS effectivePermissionKeys
    // ══════════════════════════════════════════════════════════════════════════

    public function test_permission_resolves_on_both_paths_for_normal_user_with_group_grant(): void
    {
        $user = $this->makeUser();
        $this->companyA->users()->attach($user->id);

        app(CurrentCompany::class)->set($this->companyA->id);
        $group = Group::create(['name' => 'NAV-log nézők']);
        $group->users()->attach($user->id);
        $group->permissions()->attach($this->permLogView->id);

        $checker = app(PermissionChecker::class);
        $gateResult = Gate::forUser($user)->allows('nav.log.view');
        $keyResult = in_array('nav.log.view', $checker->effectivePermissionKeys($user, $this->companyA->id), true);

        $this->assertTrue($gateResult, 'Gate-nek engednie kell a csoport-jog birtokában');
        $this->assertTrue($keyResult, 'effectivePermissionKeys-nek tartalmaznia kell a kulcsot');
        $this->assertSame($gateResult, $keyResult);
    }

    public function test_permission_resolves_on_both_paths_for_superadmin(): void
    {
        app(CurrentCompany::class)->set($this->companyA->id);

        $checker = app(PermissionChecker::class);
        $gateResult = Gate::forUser($this->superadmin)->allows('nav.log.view');
        $keyResult = in_array('nav.log.view', $checker->effectivePermissionKeys($this->superadmin, $this->companyA->id), true);

        $this->assertTrue($gateResult, 'Superadminnak a Gate-en át is mennie kell, csoport-tagság nélkül');
        $this->assertTrue($keyResult, 'Superadmin effectivePermissionKeys-ének is tartalmaznia kell a kulcsot');
        $this->assertSame($gateResult, $keyResult);
    }

    public function test_permission_denied_on_both_paths_without_group_grant(): void
    {
        $user = $this->makeUser();
        $this->companyA->users()->attach($user->id);

        app(CurrentCompany::class)->set($this->companyA->id);
        // Nincs csoport, nincs jog.

        $checker = app(PermissionChecker::class);
        $gateResult = Gate::forUser($user)->allows('nav.log.view');
        $keyResult = in_array('nav.log.view', $checker->effectivePermissionKeys($user, $this->companyA->id), true);

        $this->assertFalse($gateResult);
        $this->assertFalse($keyResult);
        $this->assertSame($gateResult, $keyResult);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Segédmetódusok (a SalesGroupTest mintáját követve)
    // ══════════════════════════════════════════════════════════════════════════

    private function makeCompany(): Company
    {
        self::$seq++;

        return Company::create([
            'name' => 'NAV Log Cég '.self::$seq,
            'tax_number' => '5234567'.self::$seq.'-2-41',
            'registration_number' => '01-09-'.str_pad(self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code' => '1000',
            'city' => 'Budapest',
            'address_line' => 'Napló u. '.self::$seq.'.',
            'base_currency' => 'HUF',
        ]);
    }

    private function makeUser(bool $superadmin = false): User
    {
        self::$seq++;

        return User::create([
            'name' => 'User '.self::$seq,
            'email' => 'navlog.user'.self::$seq.'@test.dev',
            'password' => bcrypt('password'),
            'is_superadmin' => $superadmin,
        ]);
    }

    private function makeModule(string $key): Module
    {
        return Module::create([
            'key' => $key,
            'name' => ucfirst($key),
            'description' => "Test module {$key}",
            'version' => '1.0.0',
            'is_core' => false,
            'is_available' => true,
            'sort_order' => 99,
        ]);
    }

    private function enableModule(Company $company): void
    {
        $company->enabledModules()->attach($this->navModule->id, ['enabled' => true]);
    }

    private function makeInvoice(Company $company, NavStatus $navStatus): Invoice
    {
        self::$seq++;

        $partner = Partner::withoutGlobalScope('company')->create([
            'company_id' => $company->id,
            'type' => 'customer',
            'name' => 'Teszt Vevő '.self::$seq,
            'billing_postal_code' => '1000',
            'billing_city' => 'Budapest',
            'billing_address_line' => 'Fő u. 1.',
            'default_currency' => 'HUF',
        ]);

        $paymentMethod = PaymentMethod::firstOrCreate(
            ['code' => 'CASH'],
            ['name' => 'Készpénz', 'is_active' => true]
        );

        $series = DocumentSeries::withoutGlobalScope('company')->firstOrCreate(
            ['company_id' => $company->id, 'document_type' => DocumentType::Invoice, 'prefix' => 'SZ'],
            ['reset_yearly' => true, 'next_number' => 1]
        );

        $user = User::create([
            'name' => 'Kiállító '.self::$seq,
            'email' => 'navlog.issuer'.self::$seq.'@test.dev',
            'password' => bcrypt('password'),
        ]);

        return Invoice::withoutGlobalScope('company')->create([
            'company_id' => $company->id,
            'partner_id' => $partner->id,
            'document_series_id' => $series->id,
            'invoice_number' => sprintf('SZ-%s-%06d', now()->format('Ym'), self::$seq),
            'issue_date' => now()->toDateString(),
            'fulfillment_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'currency' => 'HUF',
            'exchange_rate' => 1.0,
            'exchange_rate_date' => now()->toDateString(),
            'payment_method_id' => $paymentMethod->id,
            'status' => InvoiceStatus::Issued,
            'payment_status' => PaymentStatus::Open,
            'net_total' => 10000.00,
            'vat_total' => 2700.00,
            'gross_total' => 12700.00,
            'gross_total_base_currency' => 12700.00,
            'created_by' => $user->id,
            'nav_status' => $navStatus,
            'nav_transaction_id' => 'TRX-'.self::$seq,
            'nav_sent_at' => now(),
        ]);
    }

    private function makeLog(Invoice $invoice, array $overrides = []): NavSubmissionLog
    {
        static $attemptCounter = 0;
        $attemptCounter++;

        // created_at is intentionally NOT fillable (see NavSubmissionLog) — create()
        // always stamps "now"; a backdated override is applied via a second save()
        // (which does not re-touch created_at, since UPDATED_AT is null on this model).
        $createdAt = $overrides['created_at'] ?? null;
        unset($overrides['created_at']);

        $log = NavSubmissionLog::create(array_merge([
            'invoice_id' => $invoice->id,
            'company_id' => $invoice->company_id,
            'attempt_number' => $attemptCounter,
            'operation' => 'manageInvoice',
            'invoice_operation' => 'CREATE',
            'environment' => 'test',
            'transaction_id' => $invoice->nav_transaction_id,
            'request_xml' => null,
            'response_xml' => null,
            'status' => 'success',
        ], $overrides));

        if ($createdAt !== null) {
            $log->created_at = $createdAt;
            $log->save();
        }

        return $log;
    }

    private function asAdmin(Company $company): static
    {
        return $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $company->id])
            ->withHeader('X-Company-Id', (string) $company->id);
    }

    private function asUser(User $user, Company $company): static
    {
        return $this->actingAs($user)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $company->id])
            ->withHeader('X-Company-Id', (string) $company->id);
    }
}
