<?php

namespace App\Models;

use App\Enums\ProductType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id', 'sku', 'name', 'description', 'unit', 'type',
    'vat_rate_id', 'base_price', 'base_currency', 'is_active',
])]
class Product extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'type' => ProductType::class,
            'base_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function vatRate(): BelongsTo
    {
        return $this->belongsTo(VatRate::class);
    }

    public function prices(): HasMany
    {
        return $this->hasMany(ProductPrice::class);
    }
}
