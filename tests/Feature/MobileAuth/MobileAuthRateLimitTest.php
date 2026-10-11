<?php

namespace Tests\Feature\MobileAuth;

use App\Contracts\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakeSmsSender;
use Tests\TestCase;

/**
 * Throttling is on here (the other mobile tests switch it off).
 */
class MobileAuthRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+37491440221';

    private FakeSmsSender $sms;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sms = new FakeSmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
    }

    private function ask(string $phone = self::PHONE, string $ip = '10.0.0.1'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/otp/request', ['phone' => $phone, 'app' => 'rider']);
    }

    private function guess(string $code = '000000', string $phone = self::PHONE, string $ip = '10.0.0.1'): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/otp/verify', [
            'phone' => $phone, 'code' => $code, 'app' => 'rider', 'device_id' => 'device-0001',
        ]);
    }

    public function test_a_second_code_request_within_a_minute_is_throttled_with_retry_after(): void
    {
        $this->ask()->assertStatus(202);
        $throttled = $this->ask()->assertStatus(429);

        $this->assertGreaterThan(0, (int) $throttled->headers->get('Retry-After'));
        $this->assertCount(1, $this->sms->sent, 'the throttled request must not send an SMS');
    }

    public function test_the_resend_cooldown_is_a_minute(): void
    {
        $this->travelTo('2026-10-11 10:00:00');
        $this->ask()->assertStatus(202);

        $this->travelTo('2026-10-11 10:00:30');
        $this->ask()->assertStatus(429);

        $this->travelTo('2026-10-11 10:01:01');
        $this->ask()->assertStatus(202);
    }

    public function test_a_phone_can_get_five_codes_an_hour_whatever_the_ip(): void
    {
        $this->travelTo('2026-10-11 10:00:00');

        foreach (range(1, 5) as $i) {
            $this->ask(ip: "10.0.1.{$i}")->assertStatus(202);
        }
        $this->ask(ip: '10.0.1.99')->assertStatus(429);

        $this->travelTo('2026-10-11 11:00:01');
        $this->ask(ip: '10.0.1.99')->assertStatus(202);
    }

    public function test_one_ip_cannot_spray_codes_at_many_phones(): void
    {
        foreach (range(1, 30) as $i) {
            $this->ask(phone: '+3749'.(1000000 + $i), ip: '10.0.2.1')->assertStatus(202);
        }

        $this->ask(phone: '+37499555555', ip: '10.0.2.1')->assertStatus(429);
        $this->ask(phone: '+37499555555', ip: '10.0.2.2')->assertStatus(202);
    }

    public function test_the_phone_key_is_the_normalised_number_so_formatting_tricks_do_not_help(): void
    {
        $this->ask('+37491440221')->assertStatus(202);
        $this->ask('091 440 221')->assertStatus(429);
        $this->ask('0037491440221')->assertStatus(429);
    }

    public function test_asking_for_codes_does_not_use_up_the_verification_budget(): void
    {
        $this->ask()->assertStatus(202);
        $this->ask()->assertStatus(429);
        $this->ask()->assertStatus(429);

        $this->guess()->assertStatus(422);
    }

    public function test_guessing_does_not_use_up_the_request_budget_and_has_its_own_limit(): void
    {
        foreach (range(1, 10) as $i) {
            $this->guess()->assertStatus(422);
        }
        $this->guess()->assertStatus(429);

        $this->ask()->assertStatus(202);
    }

    public function test_guessing_is_limited_per_ip_across_phones(): void
    {
        foreach (range(1, 30) as $i) {
            $this->guess(phone: '+3749'.(2000000 + $i), ip: '10.0.3.1')->assertStatus(422);
        }

        $this->guess(phone: '+37499666666', ip: '10.0.3.1')->assertStatus(429);
    }

    public function test_guessing_is_limited_per_phone_across_ips(): void
    {
        $this->travelTo('2026-10-11 10:00:00');
        foreach (range(1, 30) as $i) {
            $this->guess(ip: '10.0.4.'.$i)->assertStatus(422);
        }

        $this->guess(ip: '10.0.4.200')->assertStatus(429);
    }

    public function test_refresh_and_social_login_have_their_own_limits(): void
    {
        foreach (range(1, 30) as $i) {
            $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'x'])->assertUnauthorized();
        }
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'x'])->assertStatus(429);

        foreach (range(1, 20) as $i) {
            $this->postJson('/api/v1/auth/social/google', ['token' => 'x', 'app' => 'rider', 'device_id' => 'device-0001'])->assertStatus(503);
        }
        $this->postJson('/api/v1/auth/social/google', ['token' => 'x', 'app' => 'rider', 'device_id' => 'device-0001'])->assertStatus(429);
    }
}
