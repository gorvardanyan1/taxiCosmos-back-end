<?php

namespace App\Enums;

enum DriverAvailability: string
{
    case Offline = 'offline';
    case Online = 'online';
    case OnTrip = 'on_trip';
}
