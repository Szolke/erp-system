<?php

namespace Tests\Feature;

use App\Enums\EnyugtaMode;
use App\Models\EnyugtaVatCategory;
use App\Services\Enyugta\EnyugtaClientInterface;
use App\Services\Enyugta\MockEnyugtaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * erp:sync-enyugta-vat-categories parancs tesztjei.
 *
 * Fedi:
 *   - mock módban lefut és feltölti a táblát
 *   - idempotens: kétszeri futtatás után a sorok SZÁMA változatlan, synced_at frissül
 *   - hiányzó base URL test/live módban → tiszta hibaüzenet, exit code 1 (nem exception)
 */
class SyncEnyugtaVatCategoriesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->forgetScopedInstances();
    }

    public function test_command_runs_in_mock_mode_and_populates_table(): void
    {
        $this->artisan('erp:sync-enyugta-vat-categories')->assertSuccessful();

        $mockClient = new MockEnyugtaClient();
        $this->assertSame(count($mockClient->fetchVatCategories()), EnyugtaVatCategory::count());

        foreach ($mockClient->fetchVatCategories() as $name) {
            $this->assertDatabaseHas('enyugta_vat_categories', ['name' => $name]);
        }
    }

    public function test_command_is_idempotent_row_count_unchanged_synced_at_updated(): void
    {
        $this->artisan('erp:sync-enyugta-vat-categories')->assertSuccessful();

        $countAfterFirst = EnyugtaVatCategory::count();
        $firstSyncedAt = EnyugtaVatCategory::orderBy('id')->first()->synced_at;

        // Determinisztikus időbélyeg-eltolódás, hogy a synced_at frissülése biztosan mérhető legyen.
        $this->travel(1)->minutes();

        $this->artisan('erp:sync-enyugta-vat-categories')->assertSuccessful();

        $countAfterSecond = EnyugtaVatCategory::count();
        $secondSyncedAt = EnyugtaVatCategory::orderBy('id')->first()->synced_at;

        $this->assertSame($countAfterFirst, $countAfterSecond, 'A második futtatás után a sorok száma NEM változhat (idempotencia).');
        $this->assertTrue($secondSyncedAt->greaterThan($firstSyncedAt), 'A synced_at mezőnek frissülnie kell minden futtatáskor.');
    }

    public function test_command_exits_cleanly_when_no_base_url_configured_in_test_mode(): void
    {
        $this->app->bind(EnyugtaClientInterface::class, function () {
            return new \App\Services\Enyugta\HttpEnyugtaClient(mode: EnyugtaMode::Test, baseUrlOverride: null);
        });
        config(['erp.enyugta.base_url_test' => null]);

        $this->artisan('erp:sync-enyugta-vat-categories')
            ->assertFailed();

        $this->assertSame(0, EnyugtaVatCategory::count());
    }
}
