<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['currency_code', 'rate_date', 'rate', 'unit', 'source'])]
class ExchangeRate extends Model
{
    protected function casts(): array
    {
        return [
            'rate_date' => 'date',
            'rate' => 'decimal:6',
        ];
    }
}
