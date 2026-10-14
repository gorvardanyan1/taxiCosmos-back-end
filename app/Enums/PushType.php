<?php

namespace App\Enums;

/**
 * The push notifications the apps get (P8-T4): who gets each one, how urgent it is and its texts (always in the user's
 * language; every text is in lang/ru.json and lang/hy.json). The text never carries names, addresses or money: the push
 * says what happened and carries a type and ids, and the app re-fetches the details over REST.
 */
enum PushType: string
{
    case TripOffer = 'trip_offer';
    case TripMatched = 'trip_matched';
    case TripArrived = 'trip_arrived';
    case TripCompleted = 'trip_completed';
    case NoDriverFound = 'no_driver_found';
    case DocumentApproved = 'document_approved';
    case DocumentRejected = 'document_rejected';
    case PayoutPaid = 'payout_paid';
    case SupportReply = 'support_reply';
    case AccountSuspended = 'account_suspended';

    /**
     * The apps this notification goes to (a person with both apps has a device per app).
     *
     * @return list<AppClient>
     */
    public function apps(): array
    {
        return match ($this) {
            self::TripOffer, self::DocumentApproved, self::DocumentRejected, self::PayoutPaid => [AppClient::Driver],
            self::TripMatched, self::TripArrived, self::TripCompleted, self::NoDriverFound => [AppClient::Rider],
            self::SupportReply, self::AccountSuspended => [AppClient::Rider, AppClient::Driver],
        };
    }

    public function title(string $locale): string
    {
        return match ($this) {
            self::TripOffer => __('New trip request', [], $locale),
            self::TripMatched => __('Driver found', [], $locale),
            self::TripArrived => __('Your driver has arrived', [], $locale),
            self::TripCompleted => __('Trip completed', [], $locale),
            self::NoDriverFound => __('No driver found', [], $locale),
            self::DocumentApproved => __('Document approved', [], $locale),
            self::DocumentRejected => __('Document rejected', [], $locale),
            self::PayoutPaid => __('Payout sent', [], $locale),
            self::SupportReply => __('New reply from support', [], $locale),
            self::AccountSuspended => __('Account suspended', [], $locale),
        };
    }

    public function body(string $locale): string
    {
        return match ($this) {
            self::TripOffer => __('A rider is waiting nearby. Tap to see the offer.', [], $locale),
            self::TripMatched => __('A driver accepted your trip and is on the way.', [], $locale),
            self::TripArrived => __('Your driver is waiting at the pickup point.', [], $locale),
            self::TripCompleted => __('Thanks for riding with us. Tap to see the details.', [], $locale),
            self::NoDriverFound => __('We could not find a driver for your trip. Please try again.', [], $locale),
            self::DocumentApproved => __('One of your documents was approved.', [], $locale),
            self::DocumentRejected => __('One of your documents was rejected. Open the app to see why and upload a new one.', [], $locale),
            self::PayoutPaid => __('Your payout has been sent to your bank account.', [], $locale),
            self::SupportReply => __('Our team answered your request. Tap to read the reply.', [], $locale),
            self::AccountSuspended => __('Your account has been suspended. Contact support if you think this is a mistake.', [], $locale),
        };
    }

    /** Delivered with high priority, so it wakes a sleeping phone. Only the trip offer cannot wait. */
    public function isHighPriority(): bool
    {
        return $this === self::TripOffer;
    }

    /** The Android notification channel the app created for it (a dedicated, high-importance one for offers). */
    public function androidChannel(): string
    {
        return $this === self::TripOffer ? 'trip_offers' : 'default';
    }

    /** iOS "time-sensitive" interruption level (breaks through focus modes). */
    public function isTimeSensitive(): bool
    {
        return $this === self::TripOffer;
    }

    /** How long the push service keeps trying to deliver it. An offer is worthless after it expires. */
    public function ttlSeconds(): int
    {
        return $this === self::TripOffer ? (int) config('taxikosmos.push.offer_ttl_seconds') : 86400;
    }

    /** Sent even though the account is no longer active: it is the notice of the suspension itself. */
    public function isFinalNotice(): bool
    {
        return $this === self::AccountSuspended;
    }
}
