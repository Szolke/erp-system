<?php

namespace App\Providers;

use App\Enums\EnyugtaMode;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Modules\ModuleResolver;
use App\Services\Enyugta\EnyugtaClientInterface;
use App\Services\Enyugta\HttpEnyugtaClient;
use App\Services\Enyugta\MockEnyugtaClient;
use App\Services\PermissionChecker;
use App\Support\CurrentCompany;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(CurrentCompany::class);
        $this->app->scoped(PermissionChecker::class);
        $this->app->singleton(ModuleRegistry::class);
        $this->app->scoped(ModuleResolver::class);

        // Mode alapú feloldás: config('erp.enyugta.default_mode') dönti el, melyik
        // implementáció szolgálja ki az EnyugtaClientInterface-t. Alapértelmezés
        // 'mock' (l. .env.example) — ez teszi tesztkörnyezetben is NAV-kapcsolat
        // nélkül futtathatóvá az erp:sync-enyugta-vat-categories parancsot.
        // Per-céges mode-override (company_enyugta_credentials.mode) a tényleges
        // beküldő logikával együtt, egy következő fázisban kerül bekötésre.
        $this->app->bind(EnyugtaClientInterface::class, function () {
            $mode = EnyugtaMode::tryFrom(config('erp.enyugta.default_mode')) ?? EnyugtaMode::Mock;

            return $mode === EnyugtaMode::Mock
                ? new MockEnyugtaClient()
                : new HttpEnyugtaClient(mode: $mode);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Routes the RBAC catalog's module.action permission keys (e.g.
        // 'invoice.cancel') through PermissionChecker for every
        // Gate::allows()/$this->authorize() call using that key.
        //
        // Resolution order (must stay consistent with PermissionChecker::effectivePermissionKeys):
        // 1. Read company context FIRST — module-gating is per-company, even for superadmin.
        // 2. Non-dotted abilities pass through to default Laravel gates (policies etc.).
        // 3. Module gate: if the owning module is off for this company → false for everyone.
        //    'module.*' and ungated keys are always true inside isAllowed(), so they never
        //    block here. This prevents superadmin from seeing phantom features of disabled modules.
        // 4. Superadmin bypasses group/override resolution → true.
        // 5. Normal user → PermissionChecker resolves group + override logic.
        Gate::before(function (User $user, string $ability) {
            $companyId = app(CurrentCompany::class)->id();

            if (! str_contains($ability, '.')) {
                return null;
            }

            if (! app(ModuleResolver::class)->isAllowed($ability, $companyId)) {
                return false;
            }

            if ($user->is_superadmin) {
                return true;
            }

            return app(PermissionChecker::class)->check($user, $ability, $companyId);
        });
    }
}
