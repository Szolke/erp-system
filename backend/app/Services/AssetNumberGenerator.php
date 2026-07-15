<?php

namespace App\Services;

use App\Models\AssetNumberCounter;
use App\Models\Company;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Allocates the next asset name for a (company, asset type) pair:
 * {CÉG_PREFIX}_{TÍPUS_CODE}_{5-jegyű SEQ}, e.g. ACME_TEYA_00001.
 *
 * Unlike InvoiceNumberGenerator, gaps are explicitly allowed here (a failed
 * asset creation after allocation is not a compliance problem for a physical
 * inventory record the way it is for an invoice number). The counter table
 * and locking strategy still mirror InvoiceNumberGenerator (DB::transaction
 * + lockForUpdate + create-on-first-use) — that's a proven, concurrency-safe
 * pattern, reused because it's proven, not because gaplessness is required.
 *
 * CÉG_PREFIX reuses companies.group_prefix — the same per-company short alpha
 * prefix already established for sales_group display names (SalesGroupResource)
 * — rather than document_series.prefix, which is a per-document-type series
 * prefix (invoice/receipt only) with different semantics and a gapless contract
 * this generator does not need.
 */
class AssetNumberGenerator
{
    /**
     * @return array{0: AssetNumberCounter, 1: string} the counter row and the formatted name
     */
    public function next(int $companyId, int $assetTypeId, string $typeCode): array
    {
        return DB::transaction(function () use ($companyId, $assetTypeId, $typeCode) {
            $prefix = Company::query()
                ->withoutGlobalScope('company')
                ->whereKey($companyId)
                ->value('group_prefix');

            if (! $prefix) {
                throw new \RuntimeException(
                    "Company {$companyId} has no group_prefix set — cannot generate an asset name."
                );
            }

            $counter = $this->lockedCounter($companyId, $assetTypeId);

            $seq = $counter->next_seq;
            $counter->next_seq = $seq + 1;
            $counter->save();

            return [$counter, sprintf('%s_%s_%05d', $prefix, $typeCode, $seq)];
        });
    }

    private function lockedCounter(int $companyId, int $assetTypeId): AssetNumberCounter
    {
        $query = fn () => AssetNumberCounter::query()
            ->withoutGlobalScope('company')
            ->where('company_id', $companyId)
            ->where('asset_type_id', $assetTypeId)
            ->lockForUpdate();

        $counter = $query()->first();

        if ($counter !== null) {
            return $counter;
        }

        try {
            AssetNumberCounter::create([
                'company_id' => $companyId,
                'asset_type_id' => $assetTypeId,
                'next_seq' => 1,
            ]);
        } catch (QueryException) {
            // Another concurrent request created it first — fall through to re-fetch.
        }

        return $query()->firstOrFail();
    }
}
