<?php

namespace App\Support;

/**
 * An app version as the stores and Expo write it: `1.4` or `1.4.2` (digits only, no build suffix). Anything else, including
 * nothing, is not a version, and the app-config answer treats such an app as unsupported.
 */
final readonly class AppVersion
{
    private const PATTERN = '/^(\d{1,4})\.(\d{1,4})(?:\.(\d{1,4}))?$/';

    private function __construct(public int $major, public int $minor, public int $patch) {}

    public static function parse(?string $value): ?self
    {
        if ($value === null || preg_match(self::PATTERN, trim($value), $match) !== 1) {
            return null;
        }

        return new self((int) $match[1], (int) $match[2], (int) ($match[3] ?? 0));
    }

    /**
     * Negative when this version is older than the other, 0 when they are the same, positive when newer.
     */
    public function compareTo(self $other): int
    {
        return [$this->major, $this->minor, $this->patch] <=> [$other->major, $other->minor, $other->patch];
    }

    public function isOlderThan(self $other): bool
    {
        return $this->compareTo($other) < 0;
    }

    public function __toString(): string
    {
        return "{$this->major}.{$this->minor}.{$this->patch}";
    }
}
