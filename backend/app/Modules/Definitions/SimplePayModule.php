<?php

namespace App\Modules\Definitions;

use App\Modules\ModuleDescriptor;

class SimplePayModule extends ModuleDescriptor
{
    public function key(): string { return 'simplepay'; }
    public function name(): string { return 'SimplePay'; }
    public function description(): string { return 'OTP SimplePay online fizetési integráció.'; }
    public function version(): string { return '1.0.0'; }
    public function isCore(): bool { return false; }

    public function dependencies(): array { return ['invoicing']; }

    public function permissions(): array { return ['simplepay.use', 'simplepay.refund']; }

    public function settingsRoute(): ?string { return '/company#section-simplepay'; }

}
