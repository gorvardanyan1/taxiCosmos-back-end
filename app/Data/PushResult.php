<?php

namespace App\Data;

/** What a push service said about one message (or one receipt). */
final readonly class PushResult
{
    private function __construct(
        public bool $ok,
        public bool $invalidToken,
        public ?string $ticketId,
        public ?string $error,
    ) {}

    public static function accepted(?string $ticketId = null): self
    {
        return new self(true, false, $ticketId, null);
    }

    /** The token is not registered with the push service (any more): the device must be revoked. */
    public static function invalidToken(?string $error = 'DeviceNotRegistered'): self
    {
        return new self(false, true, null, $error);
    }

    /** Refused for another reason (message too big, rate limited per device, ...): not the token's fault. */
    public static function failed(string $error): self
    {
        return new self(false, false, null, $error);
    }
}
