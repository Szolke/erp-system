<?php

namespace App\Console\Commands;

use App\Models\Module;
use App\Models\Permission;
use App\Modules\ModuleRegistry;
use App\Modules\ModuleResolver;
use Illuminate\Console\Command;

class CheckPermissions extends Command
{
    protected $signature = 'erp:check-permissions';

    protected $description = 'Audit the permission catalog and module catalog sync status.';

    public function handle(ModuleResolver $resolver, ModuleRegistry $registry): int
    {
        $exitCode = self::SUCCESS;

        // ── Module catalog sync check ─────────────────────────────────────────
        $registryKeys = array_map(fn ($d) => $d->key(), $registry->all());
        $catalogKeys  = Module::pluck('key')->all();
        $unsynced     = array_values(array_diff($registryKeys, $catalogKeys));
        sort($unsynced);

        $this->newLine();
        if (! empty($unsynced)) {
            $this->warn('SZINKRONIZÁLATLAN modulok (registry-ben van, DB-ben nincs):');
            $this->table(['Kulcs'], array_map(fn ($k) => [$k], $unsynced));
            $this->warn('  → Futtasd: php artisan erp:sync-modules');
            $this->newLine();
            $exitCode = self::FAILURE;
        } else {
            $this->info('Modulkatalógus szinkronizált (registry = DB).');
        }

        // ── Permission catalog check ──────────────────────────────────────────
        $dbKeys         = Permission::query()->orderBy('key')->pluck('key')->all();
        $map            = $resolver->permissionToModuleMap();
        $descriptorKeys = array_keys($map);

        // In DB but not claimed by any descriptor → ungated cross-cutting permissions.
        $orphaned = array_values(array_diff($dbKeys, $descriptorKeys));
        sort($orphaned);

        // Declared by a descriptor but not yet in DB → PermissionSeeder gap.
        $missing = array_values(array_diff($descriptorKeys, $dbKeys));
        sort($missing);

        $this->newLine();
        $this->line('<fg=cyan>ÁRVA kulcsok</> (DB-ben van, descriptor nem hirdeti → ungated / cross-cutting):');
        if (empty($orphaned)) {
            $this->info('  Nincs árva kulcs.');
        } else {
            $this->table(['Kulcs'], array_map(fn ($k) => [$k], $orphaned));
        }

        $this->newLine();
        $this->line('<fg=cyan>HIÁNYZÓ kulcsok</> (descriptor hirdeti, DB-ben nincs → seeder-bővítés kell):');
        if (empty($missing)) {
            $this->info('  Nincs hiányzó kulcs.');
        } else {
            $this->table(
                ['Kulcs', 'Modul'],
                array_map(fn ($k) => [$k, $map[$k]], $missing)
            );
        }

        $this->newLine();
        $this->line(sprintf(
            'Összesen: %d DB-kulcs | %d descriptor-kulcs | %d árva | %d hiányzó',
            count($dbKeys),
            count($descriptorKeys),
            count($orphaned),
            count($missing),
        ));

        return $exitCode;
    }
}
