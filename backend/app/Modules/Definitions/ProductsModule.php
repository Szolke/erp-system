<?php

namespace App\Modules\Definitions;

use App\Modules\ModuleDescriptor;

class ProductsModule extends ModuleDescriptor
{
    public function key(): string { return 'products'; }
    public function name(): string { return 'Termékkatalógus'; }
    public function description(): string { return 'Termékek és szolgáltatások nyilvántartása.'; }
    public function version(): string { return '1.0.0'; }
    public function isCore(): bool { return true; }

    public function permissions(): array
    {
        return ['product.view', 'product.create', 'product.edit', 'product.delete'];
    }

    public function sidebar(): array
    {
        return [
            ['label' => 'Termékek', 'route' => '/products', 'permission' => 'product.view'],
        ];
    }
}
