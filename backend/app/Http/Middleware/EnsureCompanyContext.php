<?php

namespace App\Http\Middleware;

use App\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active company for the authenticated user and populates
 * CurrentCompany, which the BelongsToCompany trait reads for tenant scoping
 * (docs/er-model.md, principle 1).
 *
 * Resolution order: X-Company-Id header (explicit switch) > session > the
 * user's default_company_id. The resolved id is re-persisted to the session
 * so later requests that omit the header keep using the same company.
 */
class EnsureCompanyContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $companyId = $request->header('X-Company-Id')
            ?? $request->session()->get('current_company_id')
            ?? $user->default_company_id;

        $companyId = $companyId !== null ? (int) $companyId : null;

        if ($companyId !== null && ! $user->companies()->whereKey($companyId)->exists()) {
            abort(403, 'A felhasználó nem tagja a megadott cégnek.');
        }

        if ($companyId !== null) {
            $request->session()->put('current_company_id', $companyId);
        }

        app(CurrentCompany::class)->set($companyId);

        return $next($request);
    }
}
