<?php

namespace App\Modules;

use App\Models\Company;

abstract class ModuleDescriptor
{
    abstract public function key(): string;
    abstract public function name(): string;
    abstract public function description(): string;
    abstract public function version(): string;
    abstract public function isCore(): bool;

    /** Modulkulcsok, amelyek szükségesek ennek a modulnak az aktiválásához. */
    public function dependencies(): array { return []; }

    /** Modulkulcsok, amelyekkel ez a modul nem futhat egyszerre. */
    public function conflicts(): array { return []; }

    /** 'modul.művelet' formátumú jog-kulcsok, amelyeket ez a modul bevezet. */
    public function permissions(): array { return []; }

    /**
     * Config-kulcs DEFINÍCIÓK — csak séma, konkrét érték nélkül.
     * Egy elem: ['key' => ..., 'type' => 'string|bool|enum|int', 'options' => [...],
     *            'default' => ..., 'sensitive' => true]
     *
     * TODO: a tényleges company_settings registry-bekötés (olvasás/írás) külön fázis feladata.
     */
    public function settings(): array { return []; }

    /**
     * Sidebar-bejegyzések, amelyeket ez a modul ad hozzá.
     * Egy elem: ['label' => ..., 'route' => ..., 'permission' => ...]
     */
    public function sidebar(): array { return []; }

    /**
     * Side-effect a modul bekapcsolásakor. SOHA nem végezhet sémaváltoztatást.
     * Pl. alapértelmezett beállítás-sor létrehozása.
     */
    public function onEnable(Company $company): void {}

    /**
     * Side-effect a modul kikapcsolásakor. SOHA nem végezhet sémaváltoztatást.
     * Pl. cache kiürítése, függő jobok leállítása.
     */
    public function onDisable(Company $company): void {}
}
