<?php

namespace App\Modules\Definitions;

use App\Modules\ModuleDescriptor;

class ReceiptsModule extends ModuleDescriptor
{
    public function key(): string { return 'receipts'; }
    public function name(): string { return 'Nyugta'; }
    public function description(): string { return 'Nyugtakiállítás és sztornózás.'; }
    public function version(): string { return '1.0.0'; }
    public function isCore(): bool { return true; }

    public function permissions(): array
    {
        return ['receipt.view', 'receipt.create', 'receipt.cancel', 'receipt.regenerate_pdf'];
    }

}
