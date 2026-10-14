<?php

namespace App\Services\Push;

use App\Contracts\PushSender;
use App\Models\Device;
use App\Models\PushTicket;

/**
 * Reads the delivery receipts of the pushes Expo accepted (scheduled every few minutes). Expo reports a token that is no longer
 * registered there, and the device is revoked automatically. Receipts exist for about a day, so older tickets are dropped.
 */
final class PushReceiptChecker
{
    private const CHUNK = 1000;

    public function __construct(
        private readonly PushSender $sender,
        private readonly DeviceRegistry $devices,
    ) {}

    /**
     * @return array{checked: int, revoked: int, expired: int}
     */
    public function check(): array
    {
        $checked = 0;
        $revoked = 0;

        // Receipts are ready after a while; a ticket the service does not know yet stays for the next run.
        $due = now()->subMinutes((int) config('taxikosmos.push.receipt_delay_minutes'));

        PushTicket::query()->where('created_at', '<=', $due)->orderBy('id')->chunkById(self::CHUNK, function ($tickets) use (&$checked, &$revoked) {
            $receipts = $this->sender->receipts($tickets->pluck('ticket_id')->all());
            $read = [];

            foreach ($tickets as $ticket) {
                $receipt = $receipts[$ticket->ticket_id] ?? null;

                if ($receipt === null) {
                    continue;
                }

                $read[] = $ticket->getKey();
                $checked++;

                if ($receipt->invalidToken && ($device = Device::query()->find($ticket->device_id)) !== null && $device->isActive()) {
                    $this->devices->revoke($device);
                    $revoked++;
                }
            }

            PushTicket::query()->whereKey($read)->delete();
        });

        $expired = PushTicket::query()->where('created_at', '<=', now()->subHours((int) config('taxikosmos.push.ticket_retention_hours')))->delete();

        return ['checked' => $checked, 'revoked' => $revoked, 'expired' => $expired];
    }
}
