<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * Gate::before grants every permission/ability check to super admins
     * without having to assign every permission row to them in the DB.
     *
     *   • $user->hasPermissionTo('view animals') → true
     *   • Gate::allows('anything')                → true
     *   • $this->authorize('update', $model)      → passes
     *
     * This mirrors the frontend behaviour where usePermissions().can(...)
     * short-circuits to true when is_super_admin is set, so authorization
     * is consistent end-to-end.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Gate::before(function ($user, $ability) {
            return $user->is_super_admin ? true : null;
        });
    }
}
