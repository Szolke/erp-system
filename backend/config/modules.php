<?php

return [
    // Core modulok (is_core = true, nem kapcsolhatók ki)
    App\Modules\Definitions\InvoicingModule::class,
    App\Modules\Definitions\ReceiptsModule::class,
    App\Modules\Definitions\PartnersModule::class,
    App\Modules\Definitions\ProductsModule::class,

    // Opcionális modulok (is_core = false, cég szintjén be/kikapcsolható)
    App\Modules\Definitions\NavModule::class,
    App\Modules\Definitions\NtakModule::class,
    App\Modules\Definitions\SimplePayModule::class,
    App\Modules\Definitions\SalesGroupModule::class,
    App\Modules\Definitions\AssetsModule::class,
    App\Modules\Definitions\ReportsModule::class,
];
