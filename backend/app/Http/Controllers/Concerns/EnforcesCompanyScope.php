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
}
