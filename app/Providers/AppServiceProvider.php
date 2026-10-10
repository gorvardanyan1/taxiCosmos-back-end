<?php

namespace App\Providers;

use App\Models\User;
use App\Support\BlindIndex;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

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
        // Admin passwords: 12+ characters with mixed case, a number and a symbol, and not found in
        // known breaches (haveibeenpwned k-anonymity API).
        Password::defaults(fn () => Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised());

        Gate::before(function ($user) {
            return $user instanceof User && $user->isSuperAdmin() ? true : null;
        });
    }
}
