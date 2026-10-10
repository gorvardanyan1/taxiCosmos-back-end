<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;

/**
 * Eloquent provider for the admin session guard and password broker: only users flagged
 * is_admin are ever retrieved (by id, credentials or remember token). Role and status are
 * checked on top by the login callback and EnsureAdminAccess.
 */
class AdminUserProvider extends EloquentUserProvider
{
    protected function newModelQuery($model = null): Builder
    {
        return parent::newModelQuery($model)->where('is_admin', true);
    }
}
