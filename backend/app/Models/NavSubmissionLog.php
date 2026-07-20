<?php

namespace App\Models;

use App\Enums\NavSubmissionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Intentionally does NOT use the BelongsToCompany trait. That trait's global
 * scope and creating-hook both read the CurrentCompany singleton — but every
 * row here is written from SendInvoiceToNavJob, a queue job, where
 * CurrentCompany is never set (no EnsureCompanyContext middleware in that
 * context). company_id is instead always assigned explicitly by the writer,
 * taken from the invoice's own company_id. Do not "fix" this back to the trait.
 *
 * operation vs. invoice_operation — two different axes, kept separate:
 *   operation         = the NAV API call name (manageInvoice, queryTransactionStatus)
 *   invoice_operation = the invoice-level action passed to manageInvoice
 *                       (CREATE / MODIFY / STORNO); NULL for queryTransactionStatus rows
 */
class NavSubmissionLog extends Model
{
    const UPDATED_AT = null;

    protected $fillable = [
        'invoice_id', 'company_id', 'attempt_number',
        'operation', 'invoice_operation', 'environment', 'transaction_id',
        'processing_result', 'validation_messages',
        'request_xml', 'response_xml', 'status', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'status' => NavSubmissionStatus::class,
            'validation_messages' => 'array',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function scopeForCompany(Builder $query, int $companyId): Builder
    {
        return $query->where('company_id', $companyId);
    }
}
