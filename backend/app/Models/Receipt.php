<?php

namespace App\Models;

use App\Enums\ReceiptStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Services\Enyugta\ReceiptAlreadyReportedException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

#[Fillable([
    'company_id', 'partner_id', 'document_series_id', 'receipt_number',
    'issue_date', 'fulfillment_date', 'currency', 'exchange_rate', 'exchange_rate_date',
    'payment_method_id', 'status', 'net_total', 'vat_total', 'gross_total',
    'storno_of_receipt_id', 'pdf_path', 'created_by',
    'reported_at', 'receipt_report_id',
])]
class Receipt extends Model
{
    use BelongsToCompany;

    /**
     * Mezők, amiket egy MÁR jelentett (reported_at kitöltött) nyugta saving
     * eseménye engedélyez módosítani — kizárólag ezek, amiket a
     * ReceiptReportBuilder ír (a jelentésre-jelölés maga, illetve egy
     * korrekció a receipt_report_id-t egy ÚJ jelentésre mutatja át). Minden
     * más mező módosítása (items, totals, stb.) ReceiptAlreadyReportedException-t
     * dob — l. booted() lent. `updated_at` mindig dirty lesz Eloquent
     * timestamps miatt, ezért szerepel a listán.
     */
    private const REPORT_LOCK_EXEMPT_FIELDS = ['reported_at', 'receipt_report_id', 'updated_at'];

    protected static function booted(): void
    {
        static::saving(function (Receipt $receipt) {
            if (! $receipt->exists) {
                return; // létrehozáskor nincs korábbi jelentett állapot, amit védeni kellene
            }

            if ($receipt->getOriginal('reported_at') === null) {
                return; // sosem volt jelentve — a D2 zárolás nem érvényes rá
            }

            $forbiddenChanges = array_diff(array_keys($receipt->getDirty()), self::REPORT_LOCK_EXEMPT_FIELDS);

            if (! empty($forbiddenChanges)) {
                throw new ReceiptAlreadyReportedException(sprintf(
                    'A(z) %s nyugta már jelentve lett a NAV eNyugta rendszer felé (reported_at kitöltve), ezért nem módosítható. Érintett mezők: %s.',
                    $receipt->receipt_number ?? "#{$receipt->id}",
                    implode(', ', $forbiddenChanges),
                ));
            }
        });

        static::deleting(function (Receipt $receipt) {
            if ($receipt->reported_at !== null) {
                throw new ReceiptAlreadyReportedException(sprintf(
                    'A(z) %s nyugta már jelentve lett a NAV eNyugta rendszer felé (reported_at kitöltve), ezért nem törölhető.',
                    $receipt->receipt_number ?? "#{$receipt->id}",
                ));
            }
        });
    }

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'fulfillment_date' => 'date',
            'exchange_rate' => 'decimal:6',
            'exchange_rate_date' => 'date',
            'status' => ReceiptStatus::class,
            'net_total' => 'decimal:2',
            'vat_total' => 'decimal:2',
            'gross_total' => 'decimal:2',
            'reported_at' => 'datetime',
        ];
    }

    public function isReported(): bool
    {
        return $this->reported_at !== null;
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

    public function report(): BelongsTo
    {
        return $this->belongsTo(ReceiptReport::class, 'receipt_report_id');
    }
}
