<?php

namespace App\Models;

use App\Enums\NavEnvironment;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'company_id', 'environment', 'nav_tax_number',
    'nav_login', 'nav_password', 'nav_signing_key', 'nav_exchange_key', 'is_active',
])]
#[Hidden(['nav_login', 'nav_password', 'nav_signing_key', 'nav_exchange_key'])]
class CompanyNavCredential extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'environment' => NavEnvironment::class,
            'nav_login' => 'encrypted',
            'nav_password' => 'encrypted',
            'nav_signing_key' => 'encrypted',
            'nav_exchange_key' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }
}
