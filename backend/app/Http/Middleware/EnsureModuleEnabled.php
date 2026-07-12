<?php

namespace App\Http\Middleware;

use App\Modules\ModuleResolver;
use App\Support\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aborts with 404 if any of the required module keys are disabled for the current company.
 *
 * Usage: ->middleware('module:nav')  or  ->middleware('module:nav,simplepay')
 *
 * AND semantics: ALL listed keys must be enabled. This keeps the security posture
 * conservative — if a feature requires two modules, one disabled module is enough
 * to gate the endpoint.
 *
 * Returns 404 (not 403): a disabled module's endpoints should not exist from the
 * client's perspective. 403 would reveal that the feature exists but is inaccessible,
 * which differs from "this functionality is not provisioned for your company."
 *
 * IMPORTANT: Must run after EnsureCompanyContext (which populates CurrentCompany).
 * Always place this middleware after 'company.context' in the route chain.
 */
class EnsureModuleEnabled
{
    public function __construct(
        private readonly CurrentCompany $currentCompany,
        private readonly ModuleResolver $resolver,
    ) {}

    public function handle(Request $request, Closure $next, string ...$moduleKeys): Response
    {
        $companyId = $this->currentCompany->id();
        $enabled   = $this->resolver->enabledModuleKeys($companyId);

        foreach ($moduleKeys as $key) {
            if (! in_array($key, $enabled, true)) {
                abort(404);
            }
        }

        return $next($request);
    }
}
