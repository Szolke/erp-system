<?php

namespace Tests\Feature;

use App\Http\Controllers\Api\AssetController;
use App\Models\Asset;
use App\Models\AssetType;
use App\Models\Company;
use App\Models\Group;
use App\Models\Module;
use App\Models\Permission;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Assets module — 4. lépés: Asset CRUD HTTP coverage.
 *
 * Covers the 5 mandatory elements from the step-4 brief:
 *   1. RMB isolation (assertBelongsToCurrentCompany) — other company's asset id → 404.
 *   2. AssetType leak guard — other company's private type is not usable/visible.
 *   3. canBeDeleted() enforce — destroy honors a false result with 409.
 *   4. Authorization — asset.* keys gate every action.
 *   5. name/asset_type_id immutability — update only accepts status/serial/imei.
 */
class AssetControllerTest extends TestCase
{
    use RefreshDatabase;

    private static int $seq = 0;

    private Company $companyA;
    private Company $companyB;
    private User $superadmin;
    private Module $module;
    private AssetType $globalType;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();

        $this->companyA = $this->makeCompany('DEMO');
        $this->companyB = $this->makeCompany('BETA');

        $this->superadmin = $this->makeUser(superadmin: true);
        $this->superadmin->companies()->attach([
            $this->companyA->id => ['is_default' => true],
            $this->companyB->id => ['is_default' => false],
        ]);

        $this->module = $this->makeModule('assets');
        $this->enableModule($this->companyA);
        $this->enableModule($this->companyB);

        foreach (['asset.view', 'asset.create', 'asset.edit', 'asset.delete'] as $key) {
            Permission::create(['key' => $key, 'module' => 'asset', 'description' => $key, 'is_sensitive' => false]);
        }

        $this->globalType = AssetType::create(['company_id' => null, 'code' => 'TEYA', 'name' => 'Teya POS terminál']);
    }

    protected function tearDown(): void
    {
        app(CurrentCompany::class)->clear();
        Mockery::close();
        parent::tearDown();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // CRUD happy path
    // ══════════════════════════════════════════════════════════════════════════

    public function test_index_returns_assets_for_current_company(): void
    {
        $this->makeAsset($this->companyA, $this->globalType, 'SN-1');

        $this->asAdmin($this->companyA)
            ->getJson('/api/assets')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_store_creates_asset_with_generated_name_and_201(): void
    {
        $response = $this->asAdmin($this->companyA)
            ->postJson('/api/assets', [
                'serial_number' => 'SN-100',
                'asset_type_id' => $this->globalType->id,
            ])
            ->assertCreated();

        $response->assertJsonPath('data.name', 'DEMO_TEYA_00001');
        $response->assertJsonPath('data.serial_number', 'SN-100');
        $response->assertJsonPath('data.status', 'active');
        $this->assertDatabaseHas('assets', ['company_id' => $this->companyA->id, 'name' => 'DEMO_TEYA_00001']);
    }

    public function test_store_returns_422_when_serial_number_missing(): void
    {
        $this->asAdmin($this->companyA)
            ->postJson('/api/assets', ['asset_type_id' => $this->globalType->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('serial_number');
    }

    public function test_store_returns_422_when_duplicate_serial_number_in_same_company(): void
    {
        $this->makeAsset($this->companyA, $this->globalType, 'SN-DUP');

        $this->asAdmin($this->companyA)
            ->postJson('/api/assets', ['serial_number' => 'SN-DUP', 'asset_type_id' => $this->globalType->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('serial_number');
    }

    public function test_store_allows_same_serial_number_across_different_companies(): void
    {
        $this->makeAsset($this->companyA, $this->globalType, 'SN-SHARED');

        $this->asAdmin($this->companyB)
            ->postJson('/api/assets', ['serial_number' => 'SN-SHARED', 'asset_type_id' => $this->globalType->id])
            ->assertCreated();
    }

    public function test_store_returns_422_when_imei_duplicate_in_same_company(): void
    {
        $this->makeAsset($this->companyA, $this->globalType, 'SN-1', imei: '111122223333444');

        $this->asAdmin($this->companyA)
            ->postJson('/api/assets', [
                'serial_number' => 'SN-2',
                'imei'          => '111122223333444',
                'asset_type_id' => $this->globalType->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('imei');
    }

    public function test_show_returns_asset(): void
    {
        $asset = $this->makeAsset($this->companyA, $this->globalType, 'SN-1');

        $this->asAdmin($this->companyA)
            ->getJson("/api/assets/{$asset->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $asset->id);
    }

    public function test_update_changes_status_serial_imei(): void
    {
        $asset = $this->makeAsset($this->companyA, $this->globalType, 'SN-1');

        $this->asAdmin($this->companyA)
            ->putJson("/api/assets/{$asset->id}", [
                'serial_number' => 'SN-1-UPDATED',
                'imei'          => '999988887777666',
                'status'        => 'service',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'service')
            ->assertJsonPath('data.serial_number', 'SN-1-UPDATED');
    }

    public function test_update_with_unchanged_serial_number_and_imei_does_not_fail_uniqueness_against_itself(): void
    {
        $asset = $this->makeAsset($this->companyA, $this->globalType, 'SN-SAME', imei: '111122223333444');

        $this->asAdmin($this->companyA)
            ->putJson("/api/assets/{$asset->id}", [
                'serial_number' => 'SN-SAME',
                'imei'          => '111122223333444',
                'status'        => 'active',
            ])
            ->assertOk();
    }

    public function test_destroy_deletes_asset(): void
    {
        $asset = $this->makeAsset($this->companyA, $this->globalType, 'SN-1');

        $this->asAdmin($this->companyA)
            ->deleteJson("/api/assets/{$asset->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('assets', ['id' => $asset->id]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Kötelező elem #1 — RMB izoláció
    // ══════════════════════════════════════════════════════════════════════════

    public function test_show_returns_404_for_other_company_asset(): void
    {
        $asset = $this->makeAsset($this->companyA, $this->globalType, 'SN-1');

        $this->asAdmin($this->companyB)
            ->getJson("/api/assets/{$asset->id}")
            ->assertNotFound();
    }

    public function test_update_returns_404_for_other_company_asset(): void
    {
        $asset = $this->makeAsset($this->companyA, $this->globalType, 'SN-1');

        $this->asAdmin($this->companyB)
            ->putJson("/api/assets/{$asset->id}", ['serial_number' => 'X', 'status' => 'active'])
            ->assertNotFound();
    }

    public function test_destroy_returns_404_for_other_company_asset(): void
    {
        $asset = $this->makeAsset($this->companyA, $this->globalType, 'SN-1');

        $this->asAdmin($this->companyB)
            ->deleteJson("/api/assets/{$asset->id}")
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Kötelező elem #2 — AssetType szivárgás-védelem
    // ══════════════════════════════════════════════════════════════════════════

    public function test_store_returns_422_when_asset_type_belongs_to_other_company(): void
    {
        $privateType = AssetType::create(['company_id' => $this->companyB->id, 'code' => 'PRIV', 'name' => 'Private']);

        $this->asAdmin($this->companyA)
            ->postJson('/api/assets', ['serial_number' => 'SN-1', 'asset_type_id' => $privateType->id])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('asset_type_id');
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Kötelező elem #3 — canBeDeleted() enforce
    // ══════════════════════════════════════════════════════════════════════════

    public function test_destroy_returns_409_when_can_be_deleted_returns_false(): void
    {
        $asset = $this->makeAsset($this->companyA, $this->globalType, 'SN-1');

        app(CurrentCompany::class)->set($this->companyA->id);
        $this->actingAs($this->superadmin);

        $mocked = Mockery::mock(Asset::class)->makePartial();
        $mocked->forceFill($asset->getAttributes());
        $mocked->exists = true;
        $mocked->shouldReceive('canBeDeleted')->once()->andReturn(false);

        try {
            app(AssetController::class)->destroy($mocked);
            $this->fail('Expected a 409 HttpException, none was thrown.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }

        // The check ran before delete() — the row must still exist.
        $this->assertDatabaseHas('assets', ['id' => $asset->id]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Kötelező elem #4 — jogosítás
    // ══════════════════════════════════════════════════════════════════════════

    public function test_store_returns_403_without_asset_create_permission(): void
    {
        $user = $this->makeUser();
        $this->companyA->users()->attach($user->id);
        // No group / permission grant for asset.create.

        $this->asUser($user, $this->companyA)
            ->postJson('/api/assets', ['serial_number' => 'SN-1', 'asset_type_id' => $this->globalType->id])
            ->assertForbidden();
    }

    public function test_index_returns_404_when_module_disabled(): void
    {
        $companyC = $this->makeCompany('GAMA');
        $this->superadmin->companies()->attach($companyC->id, ['is_default' => false]);
        // assets module NOT attached to companyC

        $this->asAdmin($companyC)
            ->getJson('/api/assets')
            ->assertNotFound();
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Kötelező elem #5 — name / asset_type_id immutability
    // ══════════════════════════════════════════════════════════════════════════

    public function test_update_ignores_name_and_asset_type_id_changes(): void
    {
        $otherType = AssetType::create(['company_id' => null, 'code' => 'MOBIL', 'name' => 'Mobiltelefon']);
        $asset     = $this->makeAsset($this->companyA, $this->globalType, 'SN-1');
        $originalName = $asset->name;

        $this->asAdmin($this->companyA)
            ->putJson("/api/assets/{$asset->id}", [
                'serial_number' => 'SN-1',
                'status'        => 'active',
                'name'          => 'HACKED_NAME',
                'asset_type_id' => $otherType->id,
            ])
            ->assertOk();

        $asset->refresh();
        $this->assertSame($originalName, $asset->name);
        $this->assertSame($this->globalType->id, $asset->asset_type_id);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Helpers
    // ══════════════════════════════════════════════════════════════════════════

    private function makeCompany(string $groupPrefix): Company
    {
        self::$seq++;
        return Company::withoutGlobalScope('company')->create([
            'name'                => 'Company '.self::$seq,
            'tax_number'          => '1234567'.self::$seq.'-2-03',
            'registration_number' => '01-01-'.str_pad((string) self::$seq, 6, '0', STR_PAD_LEFT),
            'postal_code'         => '1111',
            'city'                => 'Budapest',
            'address_line'        => 'Teszt u. 1.',
            'country_code'        => 'HU',
            'base_currency'       => 'HUF',
            'group_prefix'        => $groupPrefix,
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

    private function makeModule(string $key): Module
    {
        return Module::create([
            'key' => $key, 'name' => ucfirst($key), 'description' => "Test module {$key}",
            'version' => '1.0.0', 'is_core' => false, 'is_available' => true, 'sort_order' => 10,
        ]);
    }

    private function enableModule(Company $company): void
    {
        $company->enabledModules()->attach($this->module->id, ['enabled' => true]);
    }

    private function makeAsset(Company $company, AssetType $type, string $serial, ?string $imei = null): Asset
    {
        return Asset::withoutGlobalScope('company')->create([
            'company_id'    => $company->id,
            'name'          => 'DEMO_'.$type->code.'_'.self::$seq++,
            'serial_number' => $serial,
            'imei'          => $imei,
            'asset_type_id' => $type->id,
        ]);
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
