<?php

namespace App\Modules\Definitions;

use App\Modules\ModuleDescriptor;

class NtakModule extends ModuleDescriptor
{
    public function key(): string { return 'ntak'; }
    public function name(): string { return 'NTAK'; }
    public function description(): string { return 'Nemzeti Turisztikai Adatszolgáltató Központ integráció (csak metaadat — az integráció külön fázisban készül).'; }
    public function version(): string { return '0.1.0'; }
    public function isCore(): bool { return false; }
}
