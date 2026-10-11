<?php

namespace App\Services\Sms;

use App\Contracts\SmsSender;
use Illuminate\Support\Facades\Log;

/**
 * Writes the message to the application log instead of sending it (local, testing, staging). The
 * number is masked; the text is logged because developers need the one-time code. Never use in
 * production.
 */
final class LogSmsSender implements SmsSender
{
    public function send(string $to, string $message): void
    {
        Log::info('SMS (log driver, not sent)', ['to' => self::mask($to), 'message' => $message]);
    }

    private static function mask(string $number): string
    {
        return strlen($number) <= 6 ? str_repeat('*', strlen($number)) : substr($number, 0, 4).str_repeat('*', strlen($number) - 7).substr($number, -3);
    }
}
