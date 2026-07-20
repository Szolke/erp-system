<?php

namespace App\Modules\Definitions;

use App\Modules\ModuleDescriptor;

class EnyugtaModule extends ModuleDescriptor
{
    public function key(): string { return 'enyugta'; }
    public function name(): string { return 'NAV eNyugta'; }
    public function description(): string { return 'NAV nyugtaadat-szolgáltatás (számítógéppel kiállított nyugták napi összesítő beküldése).'; }
    public function version(): string { return '1.0.0'; }
    public function isCore(): bool { return false; }

    // A nyugta (receipt) modul jelenleg core (ReceiptsModule::isCore() === true),
    // tehát ez a függőség a mai állapotban mindig teljesül — de dokumentálja a
    // tényleges architekturális kötést, és aktívvá válik, ha a receipts modul
    // valaha opcionálissá válna.
    public function dependencies(): array { return ['receipts']; }

    public function permissions(): array
    {
        return ['enyugta.view', 'enyugta.manage', 'enyugta.submit'];
    }

    // NINCS settingsRoute() override: ebben a fázisban nincs frontend UI
    // (l. feladatleírás), a "Konfigurálás →" link csak akkor kerül majd be,
    // amikor a /settings/enyugta oldal ténylegesen elkészül.

}
