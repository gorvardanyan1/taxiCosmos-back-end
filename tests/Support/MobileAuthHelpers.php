<?php

namespace Tests\Support;

use App\Contracts\SmsSender;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * Shared steps for the mobile sign-in tests. Throttling is switched off here; the tests about the
 * limits switch it back on by calling enableThrottling().
 */
trait MobileAuthHelpers
{
    protected FakeSmsSender $sms;

    protected function setUpMobileAuth(): void
    {
        $this->sms = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
        $this->withoutMiddleware(ThrottleRequests::class);
    }

    /**
     * @return array<string, mixed>
     */
    protected function verifyPayload(string $phone, string $code, string $app = 'rider', string $device = 'device-0001'): array
    {
        return ['phone' => $phone, 'code' => $code, 'app' => $app, 'device_id' => $device];
    }

    /**
     * Ask for a code and return it as the SMS carried it.
     */
    protected function requestCode(string $phone, string $app = 'rider'): string
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => $phone, 'app' => $app])->assertStatus(202);

        return $this->sms->lastCode($phone);
    }

    /**
     * Sign in by phone through the real endpoints and return the response body.
     *
     * @return array<string, mixed>
     */
    protected function signIn(string $phone, string $app = 'rider', string $device = 'device-0001'): array
    {
        $code = $this->requestCode($phone, $app);

        return $this->postJson('/api/v1/auth/otp/verify', $this->verifyPayload($phone, $code, $app, $device))->assertOk()->json();
    }

    /**
     * @return array<string, string>
     */
    protected function bearer(string $accessToken): array
    {
        return ['Authorization' => 'Bearer '.$accessToken];
    }
}
