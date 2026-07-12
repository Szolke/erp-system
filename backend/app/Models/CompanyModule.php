<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class CompanyModule extends Pivot
{
    public $table = 'company_module';

    protected function casts(): array
    {
        return [
            'enabled'    => 'boolean',
            'enabled_at' => 'datetime',
        ];
    }
}
