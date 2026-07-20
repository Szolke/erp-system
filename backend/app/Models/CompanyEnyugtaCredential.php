<?php

namespace App\Models;

use App\Enums\EnyugtaMode;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'company_id', 'login', 'password', 'signing_key', 'exchange_key', 'tax_number',
    'mode', 'base_url_override', 'send_empty_reports', 'last_verified_at',
])]
#[Hidden(['login', 'password', 'signing_key', 'exchange_key'])]
class CompanyEnyugtaCredential extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'login' => 'encrypted',
            'password' => 'encrypted',
            'signing_key' => 'encrypted',
            'exchange_key' => 'encrypted',
            'mode' => EnyugtaMode::class,
            'send_empty_reports' => 'boolean',
            'last_verified_at' => 'datetime',
        ];
    }
}
