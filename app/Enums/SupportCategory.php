<?php

namespace App\Enums;

/** What a support ticket is about. A safety ticket is always created urgent and alerts the staff by email. */
enum SupportCategory: string
{
    case Fare = 'fare';
    case LostItem = 'lost_item';
    case Safety = 'safety';
    case DriverBehavior = 'driver_behavior';
    case RiderBehavior = 'rider_behavior';
    case AppIssue = 'app_issue';
    case Other = 'other';

    /** The priority a new ticket of this category starts with. */
    public function initialPriority(): SupportPriority
    {
        return $this === self::Safety ? SupportPriority::Urgent : SupportPriority::Normal;
    }
}
