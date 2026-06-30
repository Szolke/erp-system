<?php

return [

    /*
    |--------------------------------------------------------------------------
    | NAV Online Számla — Software Identification
    |--------------------------------------------------------------------------
    |
    | Every API request must identify the software that sends it. Register at
    | https://onlineszamla.nav.gov.hu to obtain a real softwareId before going
    | to production. NAV validates this against the registered software profile.
    |
    */

    'software' => [
        'id' => env('NAV_SOFTWARE_ID', '000000000000000000'),
        'name' => env('NAV_SOFTWARE_NAME', 'ERP System'),
        'operation' => env('NAV_SOFTWARE_OPERATION', 'ONLINE_SERVICE'),
        'main_version' => env('NAV_SOFTWARE_VERSION', '1.0'),
        'dev_name' => env('NAV_SOFTWARE_DEV_NAME', ''),
        'dev_contact' => env('NAV_SOFTWARE_DEV_CONTACT', ''),
        'dev_country_code' => env('NAV_SOFTWARE_DEV_COUNTRY', 'HU'),
        'dev_tax_number' => env('NAV_SOFTWARE_DEV_TAX', ''),
    ],

    'api_urls' => [
        'production' => 'https://api.onlineszamla.nav.gov.hu/invoiceService/v3',
        'test' => 'https://api-test.onlineszamla.nav.gov.hu/invoiceService/v3',
    ],

];
