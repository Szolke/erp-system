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

    public function settings(): array
    {
        // TODO: company_settings registry-bekötés (olvasás/írás) külön fázis feladata.
        return [
            ['key' => 'mode',         'type' => 'enum',   'options' => ['sandbox', 'live'], 'default' => 'sandbox'],
            ['key' => 'currency',     'type' => 'enum',   'options' => ['HUF', 'EUR'],      'default' => 'HUF'],
            ['key' => 'merchant_id',  'type' => 'string'],
            ['key' => 'merchant_key', 'type' => 'string',  'sensitive' => true],
        ];
    }
}
