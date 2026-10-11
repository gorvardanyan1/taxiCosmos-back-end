<?php

namespace Tests\Support;

use App\Contracts\SmsSender;
use App\Exceptions\SmsDeliveryException;

/**
 * Collects the messages instead of sending them.
 */
final class FakeSmsSender implements SmsSender
{
    /** @var list<array{to: string, message: string}> */
    public array $sent = [];

    public bool $fail = false;

    public function send(string $to, string $message): void
    {
        if ($this->fail) {
            throw new SmsDeliveryException('provider down');
        }

        $this->sent[] = ['to' => $to, 'message' => $message];
    }

    /**
     * The 6-digit code in the last message sent to this number.
     */
    public function lastCode(string $to): string
    {
        foreach (array_reverse($this->sent) as $sms) {
            if ($sms['to'] === $to && preg_match('/\b(\d{6})\b/', $sms['message'], $m)) {
                return $m[1];
            }
        }

        throw new \RuntimeException("No code was sent to {$to}.");
    }
}
