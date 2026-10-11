<?php

namespace Tests\Feature\MobileAuth;

use App\Models\OtpCode;
use App\Models\User;
use App\Support\BlindIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MobileAuthHelpers;
use Tests\TestCase;

class OtpRequestTest extends TestCase
{
    use MobileAuthHelpers, RefreshDatabase;

    private const PHONE = '+37491440221';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMobileAuth();
    }

    public function test_it_sends_a_six_digit_code_by_sms(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::PHONE, 'app' => 'rider'])
            ->assertStatus(202)->assertJson(['expires_in' => 300]);

        $this->assertCount(1, $this->sms->sent);
        $this->assertSame(self::PHONE, $this->sms->sent[0]['to']);
        $this->assertMatchesRegularExpression('/\b\d{6}\b/', $this->sms->sent[0]['message']);
    }

    public function test_the_code_is_stored_only_as_a_hash(): void
    {
        $code = $this->requestCode(self::PHONE);

        $otp = OtpCode::query()->sole();
        $this->assertSame(64, strlen($otp->code_hash));
        $this->assertNotSame($code, $otp->code_hash);
        $this->assertSame(app(BlindIndex::class)->hash(self::PHONE), $otp->phone_hash);
        // Neither column, nor any other value in the row, holds the code or the phone in clear.
        $this->assertStringNotContainsString($code, json_encode($otp->getAttributes()));
        $this->assertStringNotContainsString('37491440221', json_encode($otp->getAttributes()));
        $this->assertSame(0, $otp->attempts);
    }

    public function test_the_code_expires_after_five_minutes(): void
    {
        $this->travelTo('2026-10-11 10:00:00');
        $this->requestCode(self::PHONE);

        $this->assertSame('2026-10-11 10:05:00', OtpCode::query()->sole()->expires_at->toDateTimeString());
    }

    public function test_codes_are_always_six_digits_even_with_leading_zeros(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->sms->sent = [];
            OtpCode::query()->delete();
            $this->assertMatchesRegularExpression('/^\d{6}$/', $this->requestCode(self::PHONE));
        }
    }

    public function test_the_answer_is_identical_for_known_and_unknown_phones(): void
    {
        User::factory()->rider()->withPhone('+37493123456')->create();

        $known = $this->postJson('/api/v1/auth/otp/request', ['phone' => '+37493123456', 'app' => 'rider']);
        $unknown = $this->postJson('/api/v1/auth/otp/request', ['phone' => self::PHONE, 'app' => 'rider']);

        $this->assertSame($known->status(), $unknown->status());
        $this->assertSame($known->json(), $unknown->json());
        $this->assertSame(1, User::query()->count());
    }

    public function test_requesting_does_not_create_an_account(): void
    {
        $this->requestCode(self::PHONE);

        $this->assertSame(0, User::query()->count());
    }

    public function test_a_new_request_replaces_the_previous_code(): void
    {
        $first = $this->requestCode(self::PHONE);
        $second = $this->requestCode(self::PHONE);

        $this->assertSame(2, OtpCode::query()->count());
        $this->assertSame(1, OtpCode::query()->whereNull('consumed_at')->count());

        if ($first !== $second) {
            $this->postJson('/api/v1/auth/otp/verify', $this->verifyPayload(self::PHONE, $first))->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/otp/verify', $this->verifyPayload(self::PHONE, $second))->assertOk();
    }

    public function test_every_way_of_writing_the_number_reaches_the_same_phone(): void
    {
        foreach (['091 440 221', '+374 91-440-221', '0037491440221'] as $written) {
            $this->postJson('/api/v1/auth/otp/request', ['phone' => $written, 'app' => 'rider'])->assertStatus(202);
        }

        $this->assertSame([self::PHONE, self::PHONE, self::PHONE], array_column($this->sms->sent, 'to'));
        $this->assertSame(1, OtpCode::query()->distinct()->count('phone_hash'));
    }

    public function test_bad_input_is_refused_before_any_sms(): void
    {
        foreach ([
            [['phone' => 'abc', 'app' => 'rider'], 'phone'],
            [['phone' => '+1', 'app' => 'rider'], 'phone'],
            [['phone' => '', 'app' => 'rider'], 'phone'],
            [['phone' => ['+37491440221'], 'app' => 'rider'], 'phone'],
            [['phone' => str_repeat('1', 40), 'app' => 'rider'], 'phone'],
            [['phone' => self::PHONE], 'app'],
            [['phone' => self::PHONE, 'app' => 'admin'], 'app'],
        ] as [$payload, $field]) {
            $this->postJson('/api/v1/auth/otp/request', $payload)->assertStatus(422)->assertJsonValidationErrors($field);
        }

        $this->assertSame([], $this->sms->sent);
        $this->assertSame(0, OtpCode::query()->count());
    }

    public function test_when_the_sms_provider_fails_the_code_is_useless_and_the_app_is_told(): void
    {
        $this->sms->fail = true;

        $this->postJson('/api/v1/auth/otp/request', ['phone' => self::PHONE, 'app' => 'rider'])->assertStatus(503);

        $this->assertSame(0, OtpCode::query()->whereNull('consumed_at')->count());
    }

    public function test_a_fixed_code_works_for_listed_test_phones_in_testing(): void
    {
        config([
            'taxikosmos.mobile_auth.otp.test_code' => '123456',
            'taxikosmos.mobile_auth.otp.test_phones' => ['091 440 221'],
        ]);

        $this->assertSame('123456', $this->requestCode(self::PHONE));
        $this->postJson('/api/v1/auth/otp/verify', $this->verifyPayload(self::PHONE, '123456'))->assertOk();
    }

    public function test_the_fixed_code_is_not_used_for_other_phones(): void
    {
        config(['taxikosmos.mobile_auth.otp.test_code' => '123456', 'taxikosmos.mobile_auth.otp.test_phones' => [self::PHONE]]);

        $codes = [];
        foreach (range(1, 8) as $i) {
            OtpCode::query()->delete();
            $codes[] = $this->requestCode('+37493123456');
        }

        $this->assertNotSame(['123456'], array_unique($codes));
        $this->assertGreaterThan(1, count(array_unique($codes)));
    }

    public function test_the_fixed_code_is_never_honoured_in_production(): void
    {
        config(['taxikosmos.mobile_auth.otp.test_code' => '123456', 'taxikosmos.mobile_auth.otp.test_phones' => [self::PHONE]]);
        $this->app['env'] = 'production';

        $codes = [];
        foreach (range(1, 8) as $i) {
            OtpCode::query()->delete();
            $codes[] = $this->requestCode(self::PHONE);
        }

        $this->assertGreaterThan(1, count(array_unique($codes)));
        $this->postJson('/api/v1/auth/otp/verify', $this->verifyPayload(self::PHONE, '123456'))->assertStatus(422);
    }
}
