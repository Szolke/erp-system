<?php

/*
|--------------------------------------------------------------------------
| ERP — project-specific, non-secret configuration
|--------------------------------------------------------------------------
|
| Central home for configuration that is specific to this ERP but does not
| belong in a per-integration file (nav.php, simplepay.php). First occupant:
| the eNyugta (NAV nyugtaadat-szolgáltatás) module — see docs/progress.md
| "Tervezett jövőbeli fejlesztések" for the original intent behind this file.
|
*/

return [

    'enyugta' => [

        // 'mock' | 'test' | 'live'. mock runs the full pipeline (validation,
        // logging, archiving) but returns a fixed, schema-conformant response
        // instead of making an HTTP call — this is what makes the module
        // testable in CI without a NAV connection (docs/nav-enyugta-spec-jegyzetek.md
        // 3. fejezet: nincs megerősített sandbox-elérhetőség).
        'default_mode' => env('ENYUGTA_DEFAULT_MODE', 'mock'),

        // The spec (docs/nav-enyugta-spec-jegyzetek.md 3. fejezet) does not
        // publish a concrete hostname for either environment — only the
        // '/receipt-if' context root. Left null until NAV confirms one.
        'base_url_test' => env('ENYUGTA_BASE_URL_TEST'),
        'base_url_live' => env('ENYUGTA_BASE_URL_LIVE'),

        'context_root' => '/receipt-if',

        'request_timeout' => env('ENYUGTA_REQUEST_TIMEOUT', 5),
        'retry_times' => env('ENYUGTA_RETRY_TIMES', 3),

        // Áfa tv. 257/G. §: a kibocsátást követő 3 naptári napon belüli
        // adatszolgáltatási határidő.
        'report_deadline_days' => 3,

    ],

];
