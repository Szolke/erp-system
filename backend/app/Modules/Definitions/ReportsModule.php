<?php

namespace App\Modules\Definitions;

use App\Modules\ModuleDescriptor;

class ReportsModule extends ModuleDescriptor
{
    public function key(): string { return 'reports'; }
    public function name(): string { return 'Kimutatások'; }
    public function description(): string { return 'Számla-, termék-, kintlévőség- és ÁFA-kimutatások.'; }
    public function version(): string { return '1.0.0'; }
    public function isCore(): bool { return false; }

    public function permissions(): array
    {
        return ['report.view', 'report.export'];
    }
}
