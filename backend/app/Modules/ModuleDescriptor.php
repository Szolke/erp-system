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
     * Lapos company_settings config-kulcsok sémája — kizárólag egyszerű,
     * dedikált táblát NEM igénylő beállításokhoz.
     *
     * Egy elem: ['key' => ..., 'type' => 'string|bool|enum|int', 'options' => [...],
     *            'default' => ..., 'sensitive' => true]
     *
     * FONTOS: dedikált táblát használó integrációk NEM töltik ki ezt.
     * - NAV hitelesítők    → company_nav_credentials  (per-environment, encrypted)
     * - SimplePay adatok   → company_simplepay_credentials (per-deviza, encrypted)
     * Ezeknek saját controllerük és UI-juk van — a descriptor nem felelős a tárolásukért.
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
