<?php

namespace App\Modules\Definitions;

use App\Modules\ModuleDescriptor;

class PartnersModule extends ModuleDescriptor
{
    public function key(): string { return 'partners'; }
    public function name(): string { return 'Partnertörzs'; }
    public function description(): string { return 'Vevők és szállítók kezelése.'; }
    public function version(): string { return '1.0.0'; }
    public function isCore(): bool { return true; }

    public function permissions(): array
    {
        return ['partner.view', 'partner.create', 'partner.edit', 'partner.delete'];
    }

    public function sidebar(): array
    {
        return [
            ['label' => 'Partnerek', 'route' => '/partners', 'permission' => 'partner.view'],
        ];
    }
}
