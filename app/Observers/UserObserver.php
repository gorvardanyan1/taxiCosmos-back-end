<?php

namespace App\Observers;

use App\Enums\UserStatus;
use App\Events\Realtime\UserSuspended;
use App\Models\User;

class UserObserver
{
    /**
     * A user who is no longer active (suspended, deactivated, pending deletion) must lose their
     * open sockets, so the socket service gets a user.suspended event.
     */
    public function updated(User $user): void
    {
        if ($user->wasChanged('status') && $user->status !== UserStatus::Active) {
            UserSuspended::dispatch($user->getKey(), $user->status->value);
        }
    }
}
