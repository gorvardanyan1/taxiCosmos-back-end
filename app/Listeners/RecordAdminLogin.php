<?php

namespace App\Listeners;

use App\Http\Middleware\EnforceAdminIdleTimeout;
use App\Models\User;
use Illuminate\Auth\Events\Login;

/** Stamps last_login_at and starts the idle-timeout clock for a fresh admin session. */
class RecordAdminLogin
{
    public function handle(Login $event): void
    {
        if ($event->guard !== 'web' || ! $event->user instanceof User) {
            return;
        }

        $event->user->forceFill(['last_login_at' => now()])->saveQuietly();

        if (request()->hasSession()) {
            request()->session()->put(EnforceAdminIdleTimeout::SESSION_KEY, now()->getTimestamp());
        }
    }
}
