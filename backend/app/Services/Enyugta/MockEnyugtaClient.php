<?php

namespace App\Services\Enyugta;

/**
 * Mock üzemmód (D2 döntés, docs/nav-enyugta-spec-jegyzetek.md): HTTP-hívás
 * helyett fix, séma-konform választ ad — ez teszi CI-ban tesztelhetővé a
 * modult NAV-kapcsolat/sandbox nélkül. Ez a service container alapértelmezett
 * bindingja (config('erp.enyugta.default_mode') === 'mock').
 */
class MockEnyugtaClient implements EnyugtaClientInterface
{
    /**
     * Kicsi, realisztikus fix lista — NEM a NAV tényleges katalógusa (azt a
     * HttpEnyugtaClient adná test/live módban, amikor a valódi HTTP-hívás
     * elkészül egy következő fázisban).
     *
     * @return string[]
     */
    public function fetchVatCategories(): array
    {
        return ['27%', '18%', '5%', '0%', 'AAM', 'TAM'];
    }
}
