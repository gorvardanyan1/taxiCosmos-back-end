<?php

namespace App\Enums;

enum VehicleStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case PendingReview = 'pending_review';
}
