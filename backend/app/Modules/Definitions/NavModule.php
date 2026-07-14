<?php

namespace App\Modules\Definitions;

use App\Modules\ModuleDescriptor;

class NavModule extends ModuleDescriptor
{
    public function key(): string { return 'nav'; }
    public function name(): string { return 'NAV Online Számla'; }
    public function description(): string { return 'NAV Online Számla 3.0 integráció — számla beküldés és visszajelzés.'; }
    public function version(): string { return '1.0.0'; }
    public function isCore(): bool { return false; }

    public function dependencies(): array { return ['invoicing']; }

    // 'invoice.send_nav' is the canonical DB key; the prefix does NOT imply ownership —
    // the descriptor explicitly claims this key to gate it under the nav module.
    public function permissions(): array { return ['invoice.send_nav', 'nav.view_log']; }

    public function settingsRoute(): ?string { return '/company#section-nav'; }

}
