<?php

namespace App\Modules\Definitions;

use App\Modules\ModuleDescriptor;

class InvoicingModule extends ModuleDescriptor
{
    public function key(): string { return 'invoicing'; }
    public function name(): string { return 'Számlázás'; }
    public function description(): string { return 'Számlakiállítás, sztornózás, fizetésrögzítés, gapless sorszámozás.'; }
    public function version(): string { return '1.0.0'; }
    public function isCore(): bool { return true; }

    public function permissions(): array
    {
        return [
            'invoice.view', 'invoice.create', 'invoice.cancel',
            'invoice.regenerate_pdf',
        ];
    }

    public function sidebar(): array
    {
        return [
            ['label' => 'Bizonylatok', 'route' => '/documents',  'permission' => 'invoice.view'],
        ];
    }
}
