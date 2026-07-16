<?php

namespace App\Http\Controllers\Concerns;

use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Route model binding happens before EnsureCompanyContext middleware sets
 * CurrentCompany, so the BelongsToCompany global scope is null at binding time
 * and does not filter by company_id. Every show/update/destroy that receives a
 * company-scoped model via implicit binding must call this method first.
 */
trait EnforcesCompanyScope
{
    protected function assertBelongsToCurrentCompany(Model $model): void
    {
        $currentId = app(CurrentCompany::class)->id();

        if ($currentId === null || $model->company_id !== $currentId) {
            abort(404);
        }
    }

    /**
     * Like assertBelongsToCurrentCompany(), but also accepts a GLOBAL row
     * (company_id IS NULL) — for models with a two-tier visibility scope
     * (see JobPosition, AssetType) where NULL is a valid shared row, not
     * tenant data that leaked through binding.
     */
    protected function assertBelongsToCurrentCompanyOrGlobal(Model $model): void
    {
        $currentId = app(CurrentCompany::class)->id();

        if ($currentId === null) {
            abort(404);
        }

        if ($model->company_id !== null && $model->company_id !== $currentId) {
            abort(404);
        }
    }
}
