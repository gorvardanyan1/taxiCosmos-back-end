<?php

namespace App\Contracts;

use App\Data\PushMessage;
use App\Data\PushResult;
use App\Exceptions\PushDeliveryException;

/**
 * Delivers push notifications. The implementation is chosen by PUSH_DRIVER (expo | log); controllers and services only ever
 * ask for this interface. A message is never sent from a request: PushNotifier queues a job that calls this.
 */
interface PushSender
{
    /**
     * Send the messages and say what became of each, in the same order. A token the service says is not (or no longer)
     * registered comes back as PushResult::invalidToken(); the caller revokes that device.
     *
     * @param  list<PushMessage>  $messages
     * @return list<PushResult>
     *
     * @throws PushDeliveryException when the service could not be reached or refused the whole request (retry later)
     */
    public function send(array $messages): array;

    /**
     * The delivery outcome of earlier accepted pushes (Expo reports "device not registered" here), keyed by ticket id.
     * Tickets the service does not know (yet) are left out.
     *
     * @param  list<string>  $ticketIds
     * @return array<string, PushResult>
     *
     * @throws PushDeliveryException
     */
    public function receipts(array $ticketIds): array;
}
