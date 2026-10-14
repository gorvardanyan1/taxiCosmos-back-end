<?php

namespace App\Services\Push;

use App\Contracts\PushSender;
use App\Data\PushResult;
use Illuminate\Support\Facades\Log;

/**
 * Local and staging: nothing is sent, the push is written to the log (PUSH_DRIVER=log, the default outside production).
 * The push token is never logged; `token` is a short fingerprint so a developer can tell the devices apart.
 */
final class LogPushSender implements PushSender
{
    public function send(array $messages): array
    {
        $results = [];

        foreach ($messages as $message) {
            Log::info('Push notification (not sent: PUSH_DRIVER=log)', [
                'token' => self::fingerprint($message->token),
                'title' => $message->title,
                'body' => $message->body,
                'data' => $message->data,
                'priority' => $message->highPriority ? 'high' : 'default',
                'channel' => $message->androidChannel,
                'time_sensitive' => $message->timeSensitive,
            ]);

            $results[] = PushResult::accepted();
        }

        return $results;
    }

    public function receipts(array $ticketIds): array
    {
        return [];
    }

    public static function fingerprint(string $token): string
    {
        return substr(sha1($token), 0, 10);
    }
}
