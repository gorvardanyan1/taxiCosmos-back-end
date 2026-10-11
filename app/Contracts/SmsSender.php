<?php

namespace App\Contracts;

use App\Exceptions\SmsDeliveryException;

/**
 * Sends one text message. The provider is chosen by config (SMS_DRIVER) in AppServiceProvider, so
 * switching provider (an open decision) never touches the callers.
 */
interface SmsSender
{
    /**
     * @param  string  $to  E.164 number
     *
     * @throws SmsDeliveryException when the provider did not accept the message
     */
    public function send(string $to, string $message): void;
}
