<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'currency', 'merchant_id', 'secret_key', 'sandbox', 'is_active'])]
class CompanySimplePayCredential extends Model
{
    protected $table = 'company_simplepay_credentials';
    protected function casts(): array
    {
        return [
            'secret_key' => 'encrypted',
            'sandbox'    => 'boolean',
            'is_active'  => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
