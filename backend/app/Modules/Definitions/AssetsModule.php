<?php

namespace App\Modules\Definitions;

use App\Modules\ModuleDescriptor;

class AssetsModule extends ModuleDescriptor
{
    public function key(): string { return 'assets'; }
    public function name(): string { return 'Eszközök'; }
    public function description(): string { return 'Fizikai eszközök (pl. POS terminálok) cégenkénti nyilvántartása.'; }
    public function version(): string { return '1.0.0'; }
    public function isCore(): bool { return false; }

    public function permissions(): array
    {
        return ['asset.view', 'asset.create', 'asset.edit', 'asset.delete'];
    }

}
