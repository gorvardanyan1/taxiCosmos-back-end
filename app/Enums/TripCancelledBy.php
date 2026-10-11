<?php

namespace App\Enums;

enum TripCancelledBy: string
{
    case Rider = 'rider';
    case Driver = 'driver';
    case Admin = 'admin';
    case System = 'system';
}
