<?php

namespace Tests\Support;

use App\Contracts\PushSender;
use App\Data\PushMessage;
use App\Data\PushResult;
use App\Exceptions\PushDeliveryException;

/**
 * Stands in for the Expo Push Service: records every message, and can report tokens as invalid, refuse a message, or be down.
 */
class FakePushSender implements PushSender
{
    /** @var list<PushMessage> */
    public array $sent = [];

    /** @var list<string> tokens reported as "DeviceNotRegistered" */
    public array $invalidTokens = [];

    /** @var list<string> tokens refused for another reason */
    public array $refusedTokens = [];

    public bool $down = false;

    public int $calls = 0;

    /** @var array<string, PushResult> receipts by ticket id */
    public array $receiptResults = [];

    /** @var list<list<string>> the ticket ids asked for, per call */
    public array $receiptRequests = [];

    private int $ticket = 0;

    public function send(array $messages): array
    {
        $this->calls++;

        if ($this->down) {
            throw PushDeliveryException::unavailable('Fake', 503);
        }

        $results = [];
        foreach ($messages as $message) {
            $this->sent[] = $message;
            $results[] = match (true) {
                in_array($message->token, $this->invalidTokens, true) => PushResult::invalidToken(),
                in_array($message->token, $this->refusedTokens, true) => PushResult::failed('MessageTooBig'),
                default => PushResult::accepted('ticket-'.++$this->ticket),
            };
        }

        return $results;
    }

    public function receipts(array $ticketIds): array
    {
        $this->receiptRequests[] = $ticketIds;

        if ($this->down) {
            throw PushDeliveryException::unavailable('Fake', 503);
        }

        return array_intersect_key($this->receiptResults, array_flip($ticketIds));
    }

    /** @return list<string> the tokens that were sent something */
    public function tokens(): array
    {
        return array_map(fn (PushMessage $message) => $message->token, $this->sent);
    }
}
