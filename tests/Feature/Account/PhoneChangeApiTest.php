<?php

namespace Tests\Feature\Account;

use App\Models\OtpCode;
use App\Models\User;
use App\Support\BlindIndex;
use Illuminate\Testing\TestResponse;

/**
 * P3-T5: the phone changes only through a code sent to the new number, and one number is one account.
 */
class PhoneChangeApiTest extends AccountTestCase
{
    private const NEW = '+37499123456';

    private function request(string $phone, array $headers): TestResponse
    {
        return $this->postJson('/api/v1/me/phone/request', ['phone' => $phone], $headers);
    }

    private function verify(string $phone, string $code, array $headers): TestResponse
    {
        return $this->postJson('/api/v1/me/phone/verify', ['phone' => $phone, 'code' => $code], $headers);
    }

    public function test_a_code_goes_to_the_new_number_and_entering_it_changes_the_phone(): void
    {
        $user = $this->rider();
        $headers = $this->as($user);

        $this->request('099 123 456', $headers)->assertStatus(202)->assertJson(['expires_in' => 300]);
        $this->assertCount(1, $this->sms->sent);
        $this->assertSame(self::NEW, $this->sms->sent[0]['to'], 'the code goes to the NEW number, in E.164');

        $this->verify(self::NEW, $this->sms->lastCode(self::NEW), $headers)->assertOk()->assertJsonPath('user.phone', '+374*****456');

        $fresh = $user->fresh();
        $this->assertSame(self::NEW, $fresh->phone);
        $this->assertSame(app(BlindIndex::class)->hash(self::NEW), $fresh->phone_hash, 'the blind index follows the phone');
        $this->assertSame($user->id, User::query()->wherePhone(self::NEW)->sole()->id);
        $this->assertFalse(User::query()->wherePhone('+37491210443')->exists(), 'the old number no longer finds the account');
    }

    public function test_nothing_changes_before_the_code_is_entered(): void
    {
        $user = $this->rider();

        $this->request(self::NEW, $this->as($user))->assertStatus(202);

        $this->assertSame('+37491210443', $user->fresh()->phone);
    }

    public function test_a_wrong_code_changes_nothing_and_is_counted(): void
    {
        $user = $this->rider();
        $headers = $this->as($user);
        $this->request(self::NEW, $headers);
        $code = $this->sms->lastCode(self::NEW);
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->verify(self::NEW, $wrong, $headers)->assertStatus(422)->assertJsonValidationErrors('code');

        $this->assertSame('+37491210443', $user->fresh()->phone);
        $this->assertSame(1, OtpCode::query()->sole()->attempts);
    }

    public function test_five_wrong_codes_kill_the_code(): void
    {
        $user = $this->rider();
        $headers = $this->as($user);
        $this->request(self::NEW, $headers);
        $code = $this->sms->lastCode(self::NEW);
        $wrong = $code === '000000' ? '111111' : '000000';

        for ($i = 0; $i < 5; $i++) {
            $this->verify(self::NEW, $wrong, $headers)->assertStatus(422);
        }
        $this->verify(self::NEW, $code, $headers)->assertStatus(422);

        $this->assertSame('+37491210443', $user->fresh()->phone);
    }

    public function test_a_code_for_one_number_does_not_change_the_phone_to_another(): void
    {
        $user = $this->rider();
        $headers = $this->as($user);
        $this->request(self::NEW, $headers);

        $this->verify('+37499765432', $this->sms->lastCode(self::NEW), $headers)->assertStatus(422);

        $this->assertSame('+37491210443', $user->fresh()->phone);
    }

    public function test_a_code_works_once(): void
    {
        $user = $this->rider();
        $headers = $this->as($user);
        $this->request(self::NEW, $headers);
        $code = $this->sms->lastCode(self::NEW);
        $this->verify(self::NEW, $code, $headers)->assertOk();

        $this->verify(self::NEW, $code, $headers)->assertStatus(422);
    }

    public function test_an_expired_code_is_refused(): void
    {
        $user = $this->rider();
        $headers = $this->as($user);
        $this->request(self::NEW, $headers);
        $code = $this->sms->lastCode(self::NEW);

        $this->travel(6)->minutes();

        $this->verify(self::NEW, $code, $headers)->assertStatus(422);
        $this->assertSame('+37491210443', $user->fresh()->phone);
    }

    public function test_a_number_that_belongs_to_another_account_gets_no_code_and_the_same_answer(): void
    {
        User::factory()->rider()->withPhone(self::NEW)->create();
        $user = $this->rider();
        $headers = $this->as($user);

        $taken = $this->request(self::NEW, $headers);
        $free = $this->request('+37499765432', $headers);

        $this->assertSame($free->status(), $taken->status());
        $this->assertSame($free->json(), $taken->json(), 'nothing tells a taken number from a free one');
        $this->assertCount(1, $this->sms->sent, 'only the free number was texted');
        $this->assertSame('+37499765432', $this->sms->sent[0]['to']);
    }

    public function test_a_number_taken_between_the_request_and_the_code_is_a_conflict_and_phone_hash_stays_unique(): void
    {
        $user = $this->rider();
        $headers = $this->as($user);
        $this->request(self::NEW, $headers);
        $code = $this->sms->lastCode(self::NEW);
        $owner = User::factory()->rider()->withPhone(self::NEW)->create();

        $this->verify(self::NEW, $code, $headers)->assertStatus(409)->assertJsonValidationErrors('phone');

        $this->assertSame('+37491210443', $user->fresh()->phone);
        $this->assertSame($owner->id, User::query()->wherePhone(self::NEW)->sole()->id);
    }

    public function test_a_number_of_a_deleted_account_counts_as_taken(): void
    {
        $gone = User::factory()->rider()->withPhone(self::NEW)->create();
        $gone->delete();
        $user = $this->rider();
        $headers = $this->as($user);

        $this->request(self::NEW, $headers)->assertStatus(202);

        $this->assertSame([], $this->sms->sent);
    }

    public function test_changing_to_the_current_number_is_refused(): void
    {
        $user = $this->rider();

        $this->request('+37491210443', $this->as($user))->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->assertSame([], $this->sms->sent);
    }

    public function test_an_invalid_number_is_refused(): void
    {
        $headers = $this->as($this->rider());

        $this->request('abc', $headers)->assertStatus(422)->assertJsonValidationErrors('phone');
        $this->postJson('/api/v1/me/phone/verify', ['phone' => self::NEW, 'code' => '12'], $headers)->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_a_guest_and_a_phoneless_session_cannot_start_a_change(): void
    {
        $this->postJson('/api/v1/me/phone/request', ['phone' => self::NEW])->assertUnauthorized();

        $this->request(self::NEW, $this->as(User::factory()->rider()->create()))->assertForbidden();
        $this->assertSame([], $this->sms->sent);
    }
}
