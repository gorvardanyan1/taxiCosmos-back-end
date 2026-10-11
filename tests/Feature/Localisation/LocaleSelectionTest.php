<?php

namespace Tests\Feature\Localisation;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\MobileAuthHelpers;
use Tests\TestCase;

class LocaleSelectionTest extends TestCase
{
    use MobileAuthHelpers, RefreshDatabase;

    private const PHONE = '+37491440221';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->setUpMobileAuth();
    }

    private function admin(?string $locale = null): User
    {
        return User::factory()->admin()->create(['locale' => $locale]);
    }

    private function locale(TestResponse $response): string
    {
        return $response->headers->get('Content-Language');
    }

    // ---- admin panel (web) ----

    public function test_the_signed_in_admins_saved_language_wins_over_the_browser(): void
    {
        $response = $this->actingAs($this->admin('ru'))->withHeaders(['Accept-Language' => 'hy'])->get('/admin/riders');

        $this->assertSame('ru', $this->locale($response));
        $response->assertInertia(fn ($page) => $page->where('app.locale', 'ru'));
        $this->assertStringContainsString('<html lang="ru"', $response->getContent());
    }

    public function test_each_supported_language_is_used_for_the_page(): void
    {
        foreach (['en', 'hy', 'ru'] as $locale) {
            $response = $this->actingAs($this->admin($locale))->get('/admin/riders');

            $this->assertSame($locale, $this->locale($response));
            $response->assertInertia(fn ($page) => $page->where('app.locale', $locale)->where('app.locales', ['en', 'hy', 'ru']));
        }
    }

    public function test_an_admin_without_a_saved_language_gets_the_browsers(): void
    {
        $admin = $this->admin(null);

        $this->assertSame('ru', $this->locale($this->actingAs($admin)->withHeaders(['Accept-Language' => 'ru-RU,ru;q=0.9,en;q=0.8'])->get('/admin/riders')));
        $this->assertSame('hy', $this->locale($this->actingAs($admin)->withHeaders(['Accept-Language' => 'de,hy-AM;q=0.8'])->get('/admin/riders')));
        $this->assertSame('en', $this->locale($this->actingAs($admin)->withHeaders(['Accept-Language' => 'de-DE,fr;q=0.9'])->get('/admin/riders')));
        $this->assertSame('en', $this->locale($this->actingAs($admin)->withServerVariables(['HTTP_ACCEPT_LANGUAGE' => ''])->get('/admin/riders')));
    }

    public function test_an_unsupported_saved_language_falls_back_to_english(): void
    {
        $admin = $this->admin('xx');

        $this->assertSame('en', $this->locale($this->actingAs($admin)->withServerVariables(['HTTP_ACCEPT_LANGUAGE' => ''])->get('/admin/riders')));
    }

    public function test_a_guest_can_pick_a_language_on_the_sign_in_screen_and_it_sticks_for_the_session(): void
    {
        $first = $this->get('/login?lang=ru');
        $first->assertInertia(fn ($page) => $page->where('app.locale', 'ru'));
        $this->assertSame('ru', $this->locale($first));

        // No ?lang on the next page: the session remembers.
        $this->assertSame('ru', $this->locale($this->get('/forgot-password')));
        $this->assertSame('hy', $this->locale($this->get('/login?lang=hy')));
        $this->assertSame('hy', $this->locale($this->get('/login')));
    }

    public function test_an_unsupported_lang_parameter_is_ignored(): void
    {
        $this->get('/login?lang=ru');

        $this->assertSame('ru', $this->locale($this->get('/login?lang=de')));
        $this->assertSame('ru', $this->locale($this->get('/login?lang[]=hy')));
        $this->assertSame('ru', $this->locale($this->get('/login?lang=')));
    }

    public function test_a_guest_without_a_choice_gets_the_browser_language_then_english(): void
    {
        $this->assertSame('ru', $this->locale($this->withHeaders(['Accept-Language' => 'ru'])->get('/login')));
        $this->assertSame('en', $this->locale($this->withHeaders(['Accept-Language' => 'de'])->get('/login')));
        $this->assertSame('en', $this->locale($this->withServerVariables(['HTTP_ACCEPT_LANGUAGE' => ''])->get('/login')));
    }

    public function test_the_session_choice_does_not_override_a_signed_in_admins_saved_language(): void
    {
        $this->get('/login?lang=ru');

        $this->assertSame('hy', $this->locale($this->actingAs($this->admin('hy'))->get('/admin/riders')));
    }

    public function test_the_login_page_props_carry_the_language_list_for_the_switcher(): void
    {
        $this->get('/login?lang=hy')->assertInertia(fn ($page) => $page->where('app.locales', ['en', 'hy', 'ru']));
    }

    // ---- mobile API ----

    public function test_the_api_answers_in_the_language_the_app_asks_for(): void
    {
        $en = $this->withHeaders(['Accept-Language' => 'en'])->postJson('/api/v1/auth/otp/verify', $this->verifyPayload(self::PHONE, '123456'))->assertStatus(422);
        $ru = $this->withHeaders(['Accept-Language' => 'ru'])->postJson('/api/v1/auth/otp/verify', $this->verifyPayload(self::PHONE, '123456'))->assertStatus(422);
        $hy = $this->withHeaders(['Accept-Language' => 'hy-AM'])->postJson('/api/v1/auth/otp/verify', $this->verifyPayload(self::PHONE, '123456'))->assertStatus(422);

        $this->assertSame('The code is invalid or has expired.', $en->json('message'));
        $this->assertSame('Код неверен или срок его действия истёк.', $ru->json('message'));
        $this->assertSame('Կոդը սխալ է կամ ժամկետը լրացել է։', $hy->json('errors.code.0'));
        $this->assertSame('ru', $this->locale($ru));
        $this->assertSame('hy', $this->locale($hy));
    }

    public function test_the_api_falls_back_to_the_users_saved_language_then_english(): void
    {
        $user = User::factory()->rider()->withPhone(self::PHONE)->create(['locale' => 'ru']);
        $token = $user->createToken('device', ['rider'])->plainTextToken;

        $saved = $this->withServerVariables(['HTTP_ACCEPT_LANGUAGE' => ''])->postJson('/api/v1/auth/logout-all', [], $this->bearer($token));
        $this->assertSame('ru', $this->locale($saved));

        $this->app['auth']->forgetGuards();
        $explicit = $this->withHeaders(['Accept-Language' => 'hy'])->postJson('/api/v1/auth/logout-all', [], $this->bearer($user->createToken('device-2', ['rider'])->plainTextToken));
        $this->assertSame('hy', $this->locale($explicit), 'the header wins over the saved language');

        $this->flushHeaders();
        $this->app['auth']->forgetGuards(); // the test client would otherwise keep the previous request's user
        $this->assertSame('en', $this->locale($this->withServerVariables(['HTTP_ACCEPT_LANGUAGE' => ''])->postJson('/api/v1/auth/otp/request', ['phone' => 'x', 'app' => 'rider'])));
    }

    public function test_api_validation_errors_use_the_requested_language_and_translated_field_names(): void
    {
        $response = $this->withHeaders(['Accept-Language' => 'ru'])->postJson('/api/v1/auth/otp/request', ['app' => 'rider'])->assertStatus(422);

        $this->assertSame('Поле телефон обязательно.', $response->json('errors.phone.0'));

        $hy = $this->withHeaders(['Accept-Language' => 'hy'])->postJson('/api/v1/auth/otp/request', ['app' => 'rider'])->assertStatus(422);
        $this->assertSame('Հեռախոս դաշտը պարտադիր է։', $hy->json('errors.phone.0'));
    }

    public function test_the_one_time_code_text_message_is_sent_in_the_apps_language_with_the_right_plural(): void
    {
        foreach ([['ru', 'Ваш код TaxiKosmos: %s. Он действует 5 минут.'], ['hy', 'Ձեր TaxiKosmos կոդը՝ %s։ Այն գործում է 5 րոպե։'], ['en', 'Your TaxiKosmos code is %s. It expires in 5 minutes.']] as [$locale, $format]) {
            $this->sms->sent = [];
            $this->app['cache']->flush();

            $this->withHeaders(['Accept-Language' => $locale])->postJson('/api/v1/auth/otp/request', ['phone' => self::PHONE, 'app' => 'rider'])->assertStatus(202);

            $code = $this->sms->lastCode(self::PHONE);
            $this->assertSame(sprintf($format, $code), $this->sms->sent[0]['message'], $locale);
        }
    }

    public function test_one_minute_uses_the_singular_form_in_russian(): void
    {
        config(['taxikosmos.mobile_auth.otp.ttl_minutes' => 1]);

        $this->withHeaders(['Accept-Language' => 'ru'])->postJson('/api/v1/auth/otp/request', ['phone' => self::PHONE, 'app' => 'rider'])->assertStatus(202);

        $this->assertStringEndsWith('Он действует 1 минуту.', $this->sms->sent[0]['message']);
    }
}
