<?php

namespace App\Providers;

use App\Models\User;
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
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Routes the RBAC catalog's module.action permission keys (e.g.
        // 'invoice.cancel') through PermissionChecker for every
        // Gate::allows()/$this->authorize() call using that key.
        Gate::before(function (User $user, string $ability) {
            if ($user->is_superadmin) {
                return true;
            }

            if (! str_contains($ability, '.')) {
                return null;
            }

            $companyId = app(CurrentCompany::class)->id();

            return app(PermissionChecker::class)->check($user, $ability, $companyId);
        });
    }
}
