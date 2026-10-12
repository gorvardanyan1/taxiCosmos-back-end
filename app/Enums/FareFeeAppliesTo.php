<?php

namespace App\Enums;

/**
 * Which end of the trip a fixed surcharge is for: an airport pickup fee, a toll on the drop-off
 * side, or a fee for either end.
 */
enum FareFeeAppliesTo: string
{
    case Pickup = 'pickup';
    case Dropoff = 'dropoff';
    case Both = 'both';
}
