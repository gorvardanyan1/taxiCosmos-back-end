<?php

namespace App\Data;

/**
 * One push for one device: the text already in the user's language, a `data` object (type and ids only, so the app re-fetches
 * over REST instead of trusting the payload) and the delivery hints that make a trip offer reach a sleeping phone.
 */
final readonly class PushMessage
{
    /**
     * @param  array<string, string|int>  $data
     */
    public function __construct(
        public string $token,
        public string $title,
        public string $body,
        public array $data,
        public bool $highPriority = false,
        public string $androidChannel = 'default',
        public bool $timeSensitive = false,
        public int $ttlSeconds = 86400,
    ) {}
}
