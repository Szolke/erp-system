<?php

namespace App\Models;

use App\Enums\AssetStatus;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasBlameable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['company_id', 'name', 'serial_number', 'imei', 'asset_type_id', 'status'])]
class Asset extends Model
{
    use BelongsToCompany, HasBlameable;

    protected function casts(): array
    {
        return [
            'status' => AssetStatus::class,
        ];
    }

    public function assetType(): BelongsTo
    {
        return $this->belongsTo(AssetType::class);
    }

    /**
     * Single decision point for deletability. Always true today — there is
     * no assignment feature yet (assets tied to a user/location). Once that
     * exists, this is where "assigned → not deletable, status-change only"
     * gets enforced; the future destroy() controller should call this
     * instead of re-deriving the rule inline.
     */
    public function canBeDeleted(): bool
    {
        return true;
    }
}
