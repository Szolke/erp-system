<?php

namespace App\Console\Commands;

use App\Models\Module;
use App\Modules\ModuleRegistry;
use Illuminate\Console\Command;

class SyncModules extends Command
{
    protected $signature = 'erp:sync-modules';

    /**
     * Szinkronizálja a ModuleRegistry descriptorait a `modules` DB-táblába.
     *
     * Az egyedi azonosító a `key` mező. A sort_order forrása a config/modules.php
     * felsorolási sorrendje (index * 10). Tudatos döntés: kézi DB-átrendezést egy
     * újbóli sync felülír — a config az egyetlen forrás a sorrendhez.
     *
     * SOHA nem töröl sort — csak is_available=false-ra állítja az eltűnt modulokat.
     */
    protected $description = 'Upsert the modules table from the ModuleRegistry (never deletes rows).';

    public function handle(ModuleRegistry $registry): int
    {
        $descriptors = $registry->all();

        $created    = [];
        $updated    = [];

        foreach ($descriptors as $index => $descriptor) {
            $existing = Module::where('key', $descriptor->key())->first();

            $data = [
                'name'         => $descriptor->name(),
                'description'  => $descriptor->description(),
                'version'      => $descriptor->version(),
                'is_core'      => $descriptor->isCore(),
                'is_available' => true,
                'sort_order'   => ($index + 1) * 10,
            ];

            if ($existing) {
                $existing->update($data);
                $updated[] = $descriptor->key();
            } else {
                Module::create(array_merge(['key' => $descriptor->key()], $data));
                $created[] = $descriptor->key();
            }
        }

        $presentKeys = array_map(fn ($d) => $d->key(), $descriptors);
        $unavailableKeys = Module::whereNotIn('key', $presentKeys)
            ->where('is_available', true)
            ->pluck('key')
            ->toArray();

        if (! empty($unavailableKeys)) {
            Module::whereNotIn('key', $presentKeys)->update(['is_available' => false]);
        }

        $this->printSection('Létrehozva', $created, 'info');
        $this->printSection('Frissítve', $updated, 'comment');
        $this->printSection('is_available=false (eltűnt descriptor)', $unavailableKeys, 'warn');

        $this->newLine();
        $this->info(sprintf(
            'Összesen: %d új | %d frissített | %d eltűntre állítva',
            count($created),
            count($updated),
            count($unavailableKeys),
        ));

        return self::SUCCESS;
    }

    private function printSection(string $label, array $keys, string $level): void
    {
        if (empty($keys)) {
            return;
        }

        $method = $level === 'warn' ? 'warn' : $level;
        $this->$method("{$label} (" . count($keys) . ')');
        $this->table(['Kulcs'], array_map(fn ($k) => [$k], $keys));
    }
}
