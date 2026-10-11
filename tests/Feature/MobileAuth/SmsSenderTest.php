<?php

namespace Tests\Feature\MobileAuth;

use App\Contracts\SmsSender;
use App\Services\Sms\LogSmsSender;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class SmsSenderTest extends TestCase
{
    public function test_the_log_driver_is_the_default_in_testing(): void
    {
        $this->assertInstanceOf(LogSmsSender::class, app(SmsSender::class));
    }

    public function test_the_log_driver_writes_the_message_and_masks_the_number(): void
    {
        Log::spy();

        app(SmsSender::class)->send('+37491440221', 'Your TaxiKosmos code is 123456.');

        Log::shouldHaveReceived('info')->once()->withArgs(function (string $message, array $context) {
            return $context['message'] === 'Your TaxiKosmos code is 123456.'
                && $context['to'] === '+374*****221'
                && ! str_contains(json_encode($context), '91440221');
        });
    }

    public function test_an_unknown_driver_fails_loudly(): void
    {
        config(['sms.driver' => 'carrier-pigeon']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('carrier-pigeon');

        app(SmsSender::class);
    }
}
