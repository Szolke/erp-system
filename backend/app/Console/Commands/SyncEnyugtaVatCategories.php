<?php

namespace App\Console\Commands;

use App\Models\EnyugtaVatCategory;
use App\Services\Enyugta\EnyugtaClientInterface;
use Illuminate\Console\Command;
use RuntimeException;

class SyncEnyugtaVatCategories extends Command
{
    protected $signature = 'erp:sync-enyugta-vat-categories';

    protected $description = 'Sync the NAV /vat-category/list catalog into enyugta_vat_categories (upsert by name, mock mode by default).';

    public function handle(EnyugtaClientInterface $client): int
    {
        try {
            $names = $client->fetchVatCategories();
        } catch (RuntimeException $e) {
            // Tiszta hibaüzenet + exit code 1, NEM exception/stack trace — konfigurációs
            // hiba (hiányzó base URL) vagy "még nincs implementálva" egyaránt idetartozik.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $now = now();
        $synced = [];

        foreach ($names as $name) {
            EnyugtaVatCategory::updateOrCreate(
                ['name' => $name],
                ['synced_at' => $now],
            );
            $synced[] = $name;
        }

        $this->info(sprintf('%d ÁFA-kategória szinkronizálva.', count($synced)));
        if (! empty($synced)) {
            $this->table(['Név'], array_map(fn ($n) => [$n], $synced));
        }

        return self::SUCCESS;
    }
}
