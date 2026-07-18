<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\NavStatus;
use App\Enums\PaymentStatus;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'company_id', 'partner_id', 'document_series_id', 'invoice_number',
    'issue_date', 'fulfillment_date', 'due_date',
    'currency', 'exchange_rate', 'exchange_rate_date',
    'payment_method_id', 'status', 'payment_status',
    'net_total', 'vat_total', 'gross_total', 'gross_total_base_currency',
    'storno_of_invoice_id', 'nav_status', 'nav_transaction_id', 'nav_sent_at',
    'pdf_path', 'notes', 'created_by',
])]
class Invoice extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'fulfillment_date' => 'date',
            'due_date' => 'date',
            'exchange_rate' => 'decimal:6',
            'exchange_rate_date' => 'date',
            'status' => InvoiceStatus::class,
            'payment_status' => PaymentStatus::class,
            'net_total' => 'decimal:2',
            'vat_total' => 'decimal:2',
            'gross_total' => 'decimal:2',
            'gross_total_base_currency' => 'decimal:2',
            'nav_status' => NavStatus::class,
            'nav_sent_at' => 'datetime',
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
        return $this->belongsTo(Invoice::class, 'storno_of_invoice_id');
    }

    public function stornos(): HasMany
    {
        return $this->hasMany(Invoice::class, 'storno_of_invoice_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function navSubmissionLogs(): HasMany
    {
        return $this->hasMany(NavSubmissionLog::class);
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function simplepayTransactions(): HasMany
    {
        return $this->hasMany(SimplepayTransaction::class);
    }

    /**
     * Kizárja azokat a számlákat, amikhez tartozik sztornó. A sztornózáskor az
     * eredeti számla `status`-a `issued` marad (l. InvoiceService::cancel()) —
     * ezért a sztornó-kizárást a stornos() relációval kell eldönteni, nem a
     * status mezővel.
     */
    public function scopeNotCancelled(Builder $query): Builder
    {
        return $query->whereDoesntHave('stornos');
    }
}
