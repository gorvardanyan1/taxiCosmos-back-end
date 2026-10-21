<?php

namespace App\Enums;

enum VehicleClass: string
{
    case Economy = 'economy';
    case Comfort = 'comfort';
    case Business = 'business';

    /**
     * The name shown in the apps, in the language of the request.
     */
    public function label(): string
    {
        return match ($this) {
            self::Economy => __('Economy'),
            self::Comfort => __('Comfort'),
            self::Business => __('Business'),
        };
    }
}
