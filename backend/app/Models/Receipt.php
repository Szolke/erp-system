<?php

namespace App\Models;

use App\Enums\ReceiptStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'company_id', 'partner_id', 'document_series_id', 'receipt_number',
    'issue_date', 'currency', 'exchange_rate', 'exchange_rate_date',
    'payment_method_id', 'status', 'net_total', 'vat_total', 'gross_total',
    'storno_of_receipt_id', 'pdf_path', 'created_by',
])]
class Receipt extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'exchange_rate' => 'decimal:6',
            'exchange_rate_date' => 'date',
            'status' => ReceiptStatus::class,
            'net_total' => 'decimal:2',
            'vat_total' => 'decimal:2',
            'gross_total' => 'decimal:2',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function documentSeries(): BelongsTo
    {
        return $this->belongsTo(DocumentSeries::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function stornoOf(): BelongsTo
    {
        return $this->belongsTo(Receipt::class, 'storno_of_receipt_id');
    }

    public function stornos(): HasMany
    {
        return $this->hasMany(Receipt::class, 'storno_of_receipt_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReceiptItem::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }
}
