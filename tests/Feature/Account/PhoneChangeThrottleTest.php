<?php

namespace Tests\Feature\Account;

use App\Contracts\SmsSender;
use App\Enums\AppClient;
use App\Models\User;
use App\Services\MobileAuth\MobileSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeSmsSender;
use Tests\TestCase;

/**
 * P3-T5: a phone change texts a stranger's number, so it is limited like sign-in (throttling is on here).
 */
class PhoneChangeThrottleTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_second_code_request_within_a_minute_is_refused_and_sends_no_sms(): void
    {
        $sms = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $sms);
        $user = User::factory()->rider()->withPhone('+37491210443')->create();
        $headers = ['Authorization' => 'Bearer '.app(MobileSessionService::class)->issue($user, AppClient::Rider, 'phone-1')->accessToken];

        $this->postJson('/api/v1/me/phone/request', ['phone' => '+37499123456'], $headers)->assertStatus(202);
        $this->postJson('/api/v1/me/phone/request', ['phone' => '+37499123456'], $headers)->assertStatus(429)->assertHeader('Retry-After');

        $this->assertCount(1, $sms->sent);
    }

    public function test_guessing_codes_is_limited(): void
    {
        $user = User::factory()->rider()->withPhone('+37491210443')->create();
        $headers = ['Authorization' => 'Bearer '.app(MobileSessionService::class)->issue($user, AppClient::Rider, 'phone-1')->accessToken];
        $statuses = [];

        for ($i = 0; $i < 12; $i++) {
            $statuses[] = $this->postJson('/api/v1/me/phone/verify', ['phone' => '+37499123456', 'code' => '000000'], $headers)->status();
        }

        $this->assertSame(429, end($statuses));
        $this->assertSame(10, count(array_filter($statuses, fn ($s) => $s === 422)));
    }
}
