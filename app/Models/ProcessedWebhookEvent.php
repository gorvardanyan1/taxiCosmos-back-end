<?php

namespace App\Models;

use App\Enums\PaymentGateway;
use Illuminate\Database\Eloquent\Model;

/**
 * A webhook delivery the app has already applied (P6-T1), unique per (gateway, event id): used only to spot and discard a repeat.
 */
class ProcessedWebhookEvent extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    /**
     * Record the delivery. True the first time; false when it was already recorded (the caller then discards it). The unique key
     * decides, so two deliveries of the same event arriving together cannot both win.
     */
    public static function firstTime(PaymentGateway $gateway, string $eventId): bool
    {
        return static::query()->insertOrIgnore(['gateway' => $gateway->value, 'event_id' => $eventId, 'received_at' => now()]) === 1;
    }
}
