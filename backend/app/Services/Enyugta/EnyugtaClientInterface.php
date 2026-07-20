<?php

namespace App\Services\Enyugta;

/**
 * Kontraktus a NAV eNyugta interfészhez — ebben a fázisban kizárólag az
 * áfa-kategória katalógus lekérdezését fedi (l. erp:sync-enyugta-vat-categories).
 * A tényleges napi-összesítő beküldés (create/modify/invalidate) a 2-3.
 * fázis feladata, ott bővül a kontraktus.
 */
interface EnyugtaClientInterface
{
    /**
     * A NAV /vat-category/list válaszában szereplő kategórianevek listája.
     * A NAV válasza (docs/nav-enyugta-spec-jegyzetek.md 2.1.9.2, 30. oldal)
     * KIZÁRÓLAG neveket ad vissza — nincs külön kód —, ezért ez egyszerű
     * string-lista, nem asszociatív tömb.
     *
     * @return string[]
     */
    public function fetchVatCategories(): array;
}
