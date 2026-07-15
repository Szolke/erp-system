<?php

namespace App\Modules\Definitions;

use App\Modules\ModuleDescriptor;

class SalesGroupModule extends ModuleDescriptor
{
    public function key(): string { return 'sales_group'; }
    public function name(): string { return 'Értékesítő csoportok'; }
    public function description(): string { return 'Cégenkénti értékesítő csoportok kezelése prefixelt megjelenítőnévvel.'; }
    public function version(): string { return '1.0.0'; }
    public function isCore(): bool { return false; }

    public function permissions(): array
    {
        return ['sales_group.view', 'sales_group.create', 'sales_group.edit', 'sales_group.delete'];
    }

    public function settingsRoute(): ?string { return '/settings/sales-groups'; }
}
