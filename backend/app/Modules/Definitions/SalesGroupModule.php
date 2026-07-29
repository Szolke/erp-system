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
        return [
            'sales_group.view',
            'sales_group.create',
            'sales_group.edit',
            'sales_group.delete',
            // Superadmin-only (l. PermissionChecker::SUPERADMIN_ONLY_KEYS). Itt
            // azért szerepel, hogy a modul-kapu rá is vonatkozzon: kikapcsolt
            // sales_group modulnál a superadmin se lásson fantom-menüpontot.
            'sales_group.view_cross_company',
        ];
    }

    public function settingsRoute(): ?string { return '/settings/sales-groups'; }
}
