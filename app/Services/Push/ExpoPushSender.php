<?php

namespace App\Services\Push;

use App\Contracts\PushSender;
use App\Data\PushMessage;
use App\Data\PushResult;
use App\Exceptions\PushDeliveryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The Expo Push Service (the apps are Expo apps; Expo forwards to FCM and APNs), called with Laravel's HTTP client. Up to 100
 * messages go in one request. A ticket per message says whether Expo took it; the later receipt is where Expo reports
 * "DeviceNotRegistered" for a token that has gone stale (see PushReceiptChecker). Request bodies and responses are never
 * logged: they contain push tokens.
 */
final class ExpoPushSender implements PushSender
{
    private const SEND_URL = 'https://exp.host/--/api/v2/push/send';

    private const RECEIPTS_URL = 'https://exp.host/--/api/v2/push/getReceipts';

    private const SEND_CHUNK = 100;

    private const RECEIPT_CHUNK = 1000;

    public function __construct(private readonly ?string $accessToken = null) {}

    public function send(array $messages): array
    {
        $results = [];

        foreach (array_chunk($messages, self::SEND_CHUNK) as $chunk) {
            $tickets = $this->post(self::SEND_URL, array_map($this->payload(...), $chunk))->json('data');

            if (! is_array($tickets) || count($tickets) !== count($chunk)) {
                throw PushDeliveryException::unavailable('Expo');
            }

            foreach ($tickets as $ticket) {
                $results[] = $this->result(is_array($ticket) ? $ticket : []);
            }
        }

        return $results;
    }

    public function receipts(array $ticketIds): array
    {
        $results = [];

        foreach (array_chunk($ticketIds, self::RECEIPT_CHUNK) as $chunk) {
            $receipts = $this->post(self::RECEIPTS_URL, ['ids' => array_values($chunk)])->json('data');

            if (! is_array($receipts)) {
                throw PushDeliveryException::unavailable('Expo');
            }

            foreach ($receipts as $ticketId => $receipt) {
                $results[(string) $ticketId] = $this->result(is_array($receipt) ? $receipt : []);
            }
        }

        return $results;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(PushMessage $message): array
    {
        return array_filter([
            'to' => $message->token,
            'title' => $message->title,
            'body' => $message->body,
            'data' => $message->data,
            'sound' => 'default',
            'priority' => $message->highPriority ? 'high' : 'default',
            'channelId' => $message->androidChannel,
            'ttl' => $message->ttlSeconds,
            'interruptionLevel' => $message->timeSensitive ? 'time-sensitive' : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $ticketOrReceipt
     */
    private function result(array $ticketOrReceipt): PushResult
    {
        if (($ticketOrReceipt['status'] ?? null) === 'ok') {
            return PushResult::accepted(isset($ticketOrReceipt['id']) ? (string) $ticketOrReceipt['id'] : null);
        }

        $error = (string) ($ticketOrReceipt['details']['error'] ?? 'unknown');

        return $error === 'DeviceNotRegistered' ? PushResult::invalidToken($error) : PushResult::failed($error);
    }

    /**
     * @param  array<int|string, mixed>  $body
     */
    private function post(string $url, array $body): Response
    {
        try {
            $response = $this->http()->post($url, $body);
        } catch (ConnectionException) {
            throw PushDeliveryException::unavailable('Expo');
        }

        if ($response->failed()) {
            throw PushDeliveryException::unavailable('Expo', $response->status());
        }

        return $response;
    }

    private function http(): PendingRequest
    {
        return Http::timeout((int) config('services.push.timeout'))->acceptJson()->asJson()
            ->when($this->accessToken, fn (PendingRequest $request, string $token) => $request->withToken($token));
    }
}
