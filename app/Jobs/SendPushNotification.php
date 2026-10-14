<?php

namespace App\Jobs;

use App\Enums\PushType;
use App\Services\Push\PushDelivery;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Delivers one push notification to a person's devices from the queue (Horizon), so the request that caused it never waits
 * for the push service. Retried when the push service is unavailable; ends up in failed_jobs after the last attempt.
 */
class SendPushNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    /**
     * @param  list<int>  $deviceIds
     * @param  array<string, int|string>  $data
     */
    public function __construct(
        public readonly int $userId,
        public readonly PushType $type,
        public readonly array $deviceIds,
        public readonly array $data,
    ) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [10, 60, 300];
    }

    public function handle(PushDelivery $delivery): void
    {
        $delivery->deliver($this->userId, $this->type, $this->deviceIds, $this->data);
    }
}
