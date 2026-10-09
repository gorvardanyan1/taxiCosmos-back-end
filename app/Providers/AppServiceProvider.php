<?php

namespace App\Providers;

use App\Models\User;
use App\Support\BlindIndex;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(BlindIndex::class, fn () => BlindIndex::fromConfig());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // super_admin passes every Gate/permission check; returning null defers to normal checks.
        Gate::before(function ($user) {
            return $user instanceof User && $user->isSuperAdmin() ? true : null;
        });
    }
}
