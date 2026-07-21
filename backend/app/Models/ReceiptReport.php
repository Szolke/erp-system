<?php

namespace App\Models;

use App\Enums\ReceiptReportStatus;
use App\Enums\ReceiptReportType;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'company_id', 'report_date', 'type', 'original_report_id', 'status',
    'receipt_count', 'total_net', 'total_vat', 'total_gross',
    'transaction_id', 'submitted_at', 'response_payload', 'error_message',
    'retry_count', 'generated_at',
])]
class ReceiptReport extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'report_date' => 'date',
            'type' => ReceiptReportType::class,
            'status' => ReceiptReportStatus::class,
            'receipt_count' => 'integer',
            'total_net' => 'decimal:2',
            'total_vat' => 'decimal:2',
            'total_gross' => 'decimal:2',
            'submitted_at' => 'datetime',
            'response_payload' => 'array',
            'retry_count' => 'integer',
            'generated_at' => 'datetime',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ReceiptReportLine::class);
    }

    /** A nap NORMÁL jelentése, amire ez a korrekció hivatkozik (csak type=correction esetén nem null). */
    public function original(): BelongsTo
    {
        return $this->belongsTo(ReceiptReport::class, 'original_report_id');
    }

    /**
     * Az erre a normál jelentésre hivatkozó korrekciók. NEM láncolt — minden
     * korrekció ugyanerre a normál jelentésre mutat, nem az előző korrekcióra
     * (jóváhagyott architekturális döntés, l. docs/progress.md eNyugta 2. fázis).
     */
    public function corrections(): HasMany
    {
        return $this->hasMany(ReceiptReport::class, 'original_report_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(Receipt::class);
    }
}
