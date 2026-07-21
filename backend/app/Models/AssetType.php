<?php

namespace App\Models;

use App\Models\Concerns\HasBlameable;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Asset type catalog — deliberately NOT using the BelongsToCompany trait.
 * A row is either global (company_id IS NULL — seeded base type, visible to
 * every company) or company-owned (company_id set — a private extension,
 * visible only to that company). The trait's plain equality filter can't
 * express "global OR mine", so this model defines its own global scope.
 *
 * Mirrors BelongsToCompany's queue/console behavior: if there is no active
 * company (CurrentCompany::id() === null), no filter is added at all — the
 * caller is responsible for scoping explicitly, same as job/service code
 * does today for other BelongsToCompany models.
 *
 * Does NOT auto-fill company_id on creating (unlike BelongsToCompany) — a
 * new AssetType defaults to global (NULL) unless a caller explicitly opts
 * into company ownership by setting company_id.
 */
#[Fillable(['company_id', 'code', 'name'])]
class AssetType extends Model
{
    use HasBlameable;

    protected static function booted(): void
    {
        static::addGlobalScope('visibility', function (Builder $builder) {
            $companyId = app(CurrentCompany::class)->id();

            if ($companyId !== null) {
                $builder->where(function (Builder $q) use ($companyId) {
                    $q->whereNull('company_id')->orWhere('company_id', $companyId);
                });
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
