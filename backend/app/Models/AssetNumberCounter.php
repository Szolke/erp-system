<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-(company, asset type) sequence counter for asset name generation
 * (see App\Services\AssetNumberGenerator). Mirrors DocumentSeries in shape
 * and in how it's queried under lock, minus the gapless-numbering machinery
 * (prefix/reset_yearly/last_reset_year) that DocumentSeries needs and this
 * doesn't — asset name gaps are explicitly allowed.
 */
#[Fillable(['company_id', 'asset_type_id', 'next_seq'])]
class AssetNumberCounter extends Model
{
    use BelongsToCompany;

    public function assetType(): BelongsTo
    {
        return $this->belongsTo(AssetType::class);
    }
}
