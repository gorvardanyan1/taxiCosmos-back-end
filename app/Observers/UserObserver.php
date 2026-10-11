<?php

namespace App\Observers;

use App\Enums\UserStatus;
use App\Events\Realtime\UserSuspended;
use App\Models\User;
use App\Services\MobileAuth\MobileSessionService;

class UserObserver
{
    /**
     * A deleted account is signed out everywhere.
     */
    public function deleted(User $user): void
    {
        app(MobileSessionService::class)->revokeAll($user);
    }

    /**
     * A user who is no longer active (suspended, deactivated, pending deletion) must lose their
     * open sockets, so the socket service gets a user.suspended event.
     */
    public function updated(User $user): void
    {
        if ($user->wasChanged('status') && $user->status !== UserStatus::Active) {
            // Every device loses its session at once: access tokens and the refresh tokens behind them.
            app(MobileSessionService::class)->revokeAll($user);

            UserSuspended::dispatch($user->getKey(), $user->status->value);
        }
    }
}
