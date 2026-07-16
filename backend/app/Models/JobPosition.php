<?php

namespace App\Models;

use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Job position catalog — mirrors AssetType's visibility model, not
 * BelongsToCompany: a row is either global (company_id IS NULL — visible to
 * every company) or company-owned (company_id set — a private extension,
 * visible only to that company). The trait's plain equality filter can't
 * express "global OR mine", so this model defines its own global scope.
 *
 * Mirrors BelongsToCompany's queue/console behavior: if there is no active
 * company (CurrentCompany::id() === null), no filter is added at all — the
 * caller is responsible for scoping explicitly.
 */
#[Fillable(['company_id', 'name', 'active', 'sort_order'])]
class JobPosition extends Model
{
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

    protected function casts(): array
    {
        return [
            'active'     => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
