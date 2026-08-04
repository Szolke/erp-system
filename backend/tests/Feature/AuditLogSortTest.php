<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /api/audit-logs — whitelistelt rendezés (`sort_by` / `sort_dir`).
 *
 * A `user` a kapcsolt users.name-re rendez, ehhez az index() MINDIG
 * joinolja a users táblát — l. AuditLogController SORTABLE_COLUMNS.
 */
class AuditLogSortTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private Company $company;
    private User $superadmin;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();

        $this->company = $this->makeCompany();
        $this->superadmin = $this->makeUser(superadmin: true);
        $this->superadmin->companies()->attach($this->company->id, ['is_default' => true]);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        parent::tearDown();
    }

    public function test_without_sort_params_the_previous_default_order_is_kept(): void
    {
        $old = $this->makeLog('event.old', $this->superadmin, now()->subDays(2));
        $new = $this->makeLog('event.new', $this->superadmin, now());

        $ids = $this->idsAs([]);

        $this->assertSame([$new->id, $old->id], $ids, 'Alapértelmezés: created_at szerint csökkenő');
    }

    public function test_unknown_sort_column_silently_falls_back_to_the_default_order(): void
    {
        $old = $this->makeLog('event.old', $this->superadmin, now()->subDays(2));
        $new = $this->makeLog('event.new', $this->superadmin, now());

        $ids = $this->idsAs(['sort_by' => 'nincs_ilyen', 'sort_dir' => 'asc']);

        $this->assertSame([$new->id, $old->id], $ids);
    }

    /** `user` a kapcsolt users.name-re rendez — ez a JOIN-specifikus eset. */
    public function test_sorting_by_user_uses_the_joined_users_name_column(): void
    {
        $userA = $this->makeUser();
        $userA->update(['name' => 'Aaa Actor']);
        $userZ = $this->makeUser();
        $userZ->update(['name' => 'Zzz Actor']);

        $this->makeLog('event.by_z', $userZ, now()->subMinute());
        $this->makeLog('event.by_a', $userA, now());

        $rows = $this->logsAs(['sort_by' => 'user', 'sort_dir' => 'asc'])['data'];

        $this->assertSame('Aaa Actor', $rows[0]['user']['name']);
        $this->assertSame('Zzz Actor', $rows[1]['user']['name']);
    }

    public function test_sorting_by_event_ascending(): void
    {
        $this->makeLog('zzz.event', $this->superadmin);
        $this->makeLog('aaa.event', $this->superadmin);

        $actions = array_map(
            fn ($r) => $r['action'],
            $this->logsAs(['sort_by' => 'event', 'sort_dir' => 'asc'])['data']
        );

        $this->assertSame(['aaa.event', 'zzz.event'], $actions);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    /** @return int[] */
    private function idsAs(array $query): array
    {
        return array_map(fn ($row) => $row['id'], $this->logsAs($query)['data']);
    }

    private function logsAs(array $query): array
    {
        return $this->asAdmin($this->company)
            ->getJson('/api/audit-logs?'.http_build_query($query))
            ->assertOk()
            ->json();
    }

    private function makeLog(string $action, User $user, ?\DateTimeInterface $createdAt = null): AuditLog
    {
        $log = AuditLog::create([
            'action' => $action,
            'user_id' => $user->id,
            'company_id' => $this->company->id,
            'auditable_type' => 'App\\Models\\Partner',
            'auditable_id' => 1,
            'old_values' => [],
            'new_values' => [],
        ]);

        if ($createdAt !== null) {
            $log->forceFill(['created_at' => $createdAt])->save();
        }

        return $log;
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

    private function makeUser(bool $superadmin = false): User
    {
        self::$seq++;

        return User::create([
            'name'          => 'User '.self::$seq,
            'email'         => 'user'.self::$seq.'@example.com',
            'password'      => bcrypt('password'),
            'is_superadmin' => $superadmin,
        ]);
    }

    private function asAdmin(Company $company): static
    {
        return $this->actingAs($this->superadmin)
            ->withHeader('Origin', 'http://localhost')
            ->withSession(['current_company_id' => $company->id])
            ->withHeader('X-Company-Id', (string) $company->id);
    }
}
