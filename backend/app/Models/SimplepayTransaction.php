<?php

namespace App\Models;

use App\Enums\SimplePayStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'company_id', 'invoice_id', 'order_ref', 'transaction_id', 'amount', 'currency',
    'status', 'ipn_payload', 'ipn_received_at', 'finished_at',
])]
class SimplepayTransaction extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'status' => SimplePayStatus::class,
            'ipn_payload' => 'array',
            'ipn_received_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
