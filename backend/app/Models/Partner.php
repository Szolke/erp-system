<?php

namespace App\Models;

use App\Enums\PartnerType;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasBlameable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id', 'type', 'name', 'tax_number', 'eu_tax_number', 'registration_number',
    'billing_postal_code', 'billing_city', 'billing_address_line', 'billing_country_code',
    'shipping_postal_code', 'shipping_city', 'shipping_address_line',
    'default_payment_method_id', 'default_currency', 'email', 'phone',
    'bank_account_number', 'is_active', 'custom_fields',
])]
class Partner extends Model
{
    use BelongsToCompany, HasBlameable;

    protected function casts(): array
    {
        return [
            'type'          => PartnerType::class,
            'is_active'     => 'boolean',
            'custom_fields' => 'array',
        ];
    }

    public function defaultPaymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class, 'default_payment_method_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }
}
