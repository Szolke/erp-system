<?php

namespace App\Services;

use App\Enums\DocumentType;
use App\Models\DocumentSeries;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Allocates the next gapless, per-company/per-document-type invoice or
 * receipt number (docs/er-model.md, document_series).
 *
 * Wraps allocation in its own DB::transaction() so the SELECT FOR UPDATE
 * lock is always held correctly regardless of the caller's transaction state.
 * When called from within an outer transaction (e.g. InvoiceService) Laravel
 * uses a savepoint, so an outer rollback also rolls back the number allocation.
 */
class InvoiceNumberGenerator
{
    /**
     * @return array{0: DocumentSeries, 1: string} the series row and the formatted number
     */
    public function next(int $companyId, DocumentType $documentType, string $defaultPrefix = 'SZ'): array
    {
        return DB::transaction(function () use ($companyId, $documentType, $defaultPrefix) {
            $series = $this->lockedSeries($companyId, $documentType, $defaultPrefix);

            $year = (int) now()->format('Y');

            if ($series->reset_yearly && $series->last_reset_year !== $year) {
                $series->next_number = 1;
                $series->last_reset_year = $year;
            }

            $number = $series->next_number;
            $series->next_number = $number + 1;
            $series->save();

            return [$series, sprintf('%s-%s-%06d', $series->prefix, now()->format('Ym'), $number)];
        });
    }

    private function lockedSeries(int $companyId, DocumentType $documentType, string $defaultPrefix): DocumentSeries
    {
        $query = fn () => DocumentSeries::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('document_type', $documentType)
            ->lockForUpdate();

        $series = $query()->first();

        if ($series !== null) {
            return $series;
        }

        try {
            DocumentSeries::create([
                'company_id' => $companyId,
                'document_type' => $documentType,
                'prefix' => $defaultPrefix,
                'reset_yearly' => true,
                'next_number' => 1,
            ]);
        } catch (QueryException) {
            // Another concurrent request created it first — fall through to re-fetch.
        }

        return $query()->firstOrFail();
    }
}
