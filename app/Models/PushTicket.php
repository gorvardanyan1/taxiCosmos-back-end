<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** An accepted Expo push (its ticket id) whose receipt has not been read yet. */
class PushTicket extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = [];
}
