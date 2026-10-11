<?php

namespace Tests\Feature\Localisation;

use App\Enums\AdminRole;
use App\Enums\TripStatus;
use App\Exceptions\TripStateException;
use App\Models\DriverDocument;
use App\Models\Trip;
use App\Models\User;
use App\Models\Zone;
use Database\Factories\ZoneFactory;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What an admin sees back from the server (flash messages, conflicts, validation errors, sign-in
 * errors) is in their language, from the real controllers.
 */
class TranslatedMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(string $locale, AdminRole $role = AdminRole::Admin): User
    {
        return User::factory()->admin($role)->create(['locale' => $locale]);
    }

    public function test_flash_messages_after_an_action_follow_the_admins_language(): void
    {
        $expected = ['en' => 'Rider suspended.', 'ru' => 'Пассажир заблокирован.', 'hy' => 'Ուղևորն արգելափակվեց։'];

        foreach ($expected as $locale => $message) {
            $admin = $this->admin($locale);
            $rider = User::factory()->rider()->create();

            $this->actingAs($admin)->post("/admin/riders/{$rider->id}/suspend", ['reason' => 'Test'])->assertRedirect();

            $this->actingAs($admin)->get('/admin/riders')->assertInertia(fn ($page) => $page->where('flash.success', $message));
        }
    }

    public function test_every_zone_and_document_confirmation_is_translated(): void
    {
        $admin = $this->admin('ru');

        $this->actingAs($admin)->post('/admin/zones', ['name' => 'Z', 'code' => 'ZZ-1', 'timezone' => 'Asia/Yerevan', 'currency' => 'AMD', 'polygon' => ZoneFactory::squareGeoJson(40.18, 44.51)])->assertRedirect();
        $this->actingAs($admin)->get('/admin/zones')->assertInertia(fn ($page) => $page->where('flash.success', 'Зона создана.'));

        $zone = Zone::query()->where('code', 'ZZ-1')->firstOrFail();
        $this->actingAs($admin)->post("/admin/zones/{$zone->id}/deactivate");
        $this->actingAs($admin)->get('/admin/zones')->assertInertia(fn ($page) => $page->where('flash.success', 'Зона деактивирована.'));

        $document = DriverDocument::factory()->create();
        $this->actingAs($admin)->post("/admin/drivers/{$document->driver_id}/documents/{$document->id}/approve");
        $this->actingAs($admin)->get('/admin/drivers')->assertInertia(fn ($page) => $page->where('flash.success', 'Документ одобрен.'));
    }

    public function test_conflicts_are_translated_and_keep_their_values(): void
    {
        $admin = $this->admin('hy');
        $active = User::factory()->rider()->create();

        $this->actingAs($admin)->withHeaders(['X-Inertia' => 'true'])->post("/admin/riders/{$active->id}/reactivate", ['reason' => 'x']);
        $this->flushHeaders();
        $this->actingAs($admin)->get("/admin/riders/{$active->id}")->assertInertia(fn ($page) => $page->where('flash.error', 'Վերաակտիվացնել կարելի է միայն արգելափակված ուղևորներին։'));

        $zone = Zone::factory()->create();
        $this->actingAs($admin)->withHeaders(['X-Inertia' => 'true'])->post("/admin/zones/{$zone->id}/activate");
        $this->flushHeaders();
        $this->actingAs($admin)->get('/admin/zones')->assertInertia(fn ($page) => $page->where('flash.error', 'Այս գոտին արդեն ակտիվ է։'));
    }

    public function test_a_conflict_over_json_uses_the_requested_language_and_placeholders_are_filled(): void
    {
        $trip = Trip::factory()->completed()->create();

        $english = $this->catch(fn () => $trip->transitionTo(TripStatus::InProgress));
        $this->assertSame('A trip cannot move from completed to in_progress.', $english);

        app()->setLocale('ru');
        $this->assertSame('Поездка не может перейти из статуса completed в in_progress.', $this->catch(fn () => $trip->transitionTo(TripStatus::InProgress)));

        app()->setLocale('hy');
        $this->assertSame('Ուղևորությունը չի կարող completed կարգավիճակից անցնել in_progress կարգավիճակի։', $this->catch(fn () => $trip->transitionTo(TripStatus::InProgress)));
    }

    private function catch(callable $action): string
    {
        try {
            $action();
        } catch (TripStateException $e) {
            return $e->getMessage();
        }

        $this->fail('No exception.');
    }

    public function test_validation_errors_on_admin_forms_are_translated_with_field_names(): void
    {
        $rider = User::factory()->rider()->create();

        $ru = $this->actingAs($this->admin('ru'))->patch("/admin/riders/{$rider->id}", ['name' => 'X']);
        $ru->assertSessionHasErrors(['reason' => 'Поле причина обязательно.']);

        $hy = $this->actingAs($this->admin('hy'))->patch("/admin/riders/{$rider->id}", ['name' => 'X']);
        $hy->assertSessionHasErrors(['reason' => 'Պատճառ դաշտը պարտադիր է։']);
    }

    public function test_custom_rule_messages_are_translated(): void
    {
        $rider = User::factory()->rider()->create();
        $other = User::factory()->rider()->withPhone('+37491440221')->create();

        $this->actingAs($this->admin('ru'))->patch("/admin/riders/{$rider->id}", ['phone' => '+37491440221', 'reason' => 'x'])
            ->assertSessionHasErrors(['phone' => 'Этот номер телефона уже используется другим аккаунтом.']);
        $this->actingAs($this->admin('hy'))->patch("/admin/riders/{$rider->id}", ['phone' => 'abc', 'reason' => 'x'])
            ->assertSessionHasErrors(['phone' => 'Հեռախոսը պետք է լինի վավեր համար։']);

        $this->assertSame($other->id, User::query()->wherePhone('+37491440221')->value('id'));
    }

    public function test_zone_polygon_errors_are_translated_including_the_placeholder(): void
    {
        $payload = ['name' => 'Z', 'code' => 'ZZ-2', 'timezone' => 'Asia/Yerevan', 'currency' => 'AMD'];
        $bowtie = json_encode(['type' => 'Polygon', 'coordinates' => [[[44.4, 40.1], [44.6, 40.3], [44.6, 40.1], [44.4, 40.3], [44.4, 40.1]]]]);

        $this->actingAs($this->admin('ru'))->post('/admin/zones', [...$payload, 'polygon' => $bowtie])->assertSessionHasErrors(['polygon' => 'Многоугольник пересекает сам себя.']);
        $this->actingAs($this->admin('hy'))->post('/admin/zones', [...$payload, 'polygon' => $bowtie])->assertSessionHasErrors(['polygon' => 'Բազմանկյունը հատում է ինքն իրեն։']);

        config(['taxikosmos.zones.max_vertices' => 3]);
        $this->actingAs($this->admin('ru'))->post('/admin/zones', [...$payload, 'polygon' => json_encode(['type' => 'Polygon', 'coordinates' => [[[44.4, 40.1], [44.6, 40.1], [44.6, 40.3], [44.4, 40.3], [44.4, 40.1]]]])])
            ->assertSessionHasErrors(['polygon' => 'В многоугольнике слишком много точек (не более 3).']);
    }

    public function test_the_session_timeout_and_sign_in_messages_are_translated(): void
    {
        $admin = $this->admin('ru');
        $this->actingAs($admin)->get('/admin/riders');
        $this->travel(61)->minutes();

        $this->actingAs($admin)->get('/admin/riders')->assertRedirect('/login');
        $this->get('/login')->assertInertia(fn ($page) => $page->where('status', 'Сеанс завершён после 60 мин. бездействия. Войдите снова.'));
    }

    public function test_the_wrong_password_message_is_translated_for_each_language(): void
    {
        $this->admin('en');

        foreach (['ru' => 'Неверное имя пользователя или пароль.', 'hy' => 'Մուտքագրված տվյալները սխալ են։'] as $locale => $message) {
            $this->flushSession();
            $this->get("/login?lang={$locale}");

            $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'wrong'])->assertSessionHasErrors(['email' => $message]);
        }
    }

    public function test_the_password_reset_confirmation_is_translated(): void
    {
        $this->admin('en');

        $this->get('/login?lang=ru');
        $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertSessionHas('status', 'Ссылка на сброс пароля была отправлена.');
    }

    public function test_the_error_page_of_a_missing_record_is_in_the_admins_language(): void
    {
        config(['app.debug' => false]); // the template error pages render outside debug mode
        $response = $this->actingAs($this->admin('hy'))->get('/admin/riders/999999')->assertStatus(404);

        $response->assertHeader('Content-Language', 'hy');
        $response->assertInertia(fn ($page) => $page->component('Errors/Show')->where('status', 404)->where('app.locale', 'hy'));
    }

    public function test_a_forbidden_page_is_in_the_admins_language(): void
    {
        config(['app.debug' => false]); // the template error pages render outside debug mode
        $response = $this->actingAs($this->admin('ru', AdminRole::Dispatcher))->get('/admin/zones')->assertForbidden();

        $response->assertInertia(fn ($page) => $page->component('Errors/Show')->where('app.locale', 'ru'));
    }

    public function test_an_unknown_url_uses_the_browsers_language_because_no_web_middleware_ran(): void
    {
        config(['app.debug' => false]); // the template error pages render outside debug mode
        $this->withHeaders(['Accept-Language' => 'ru'])->get('/admin/no-such-page')->assertNotFound()
            ->assertInertia(fn ($page) => $page->component('Errors/Show')->where('locale', 'ru'));
        $this->withHeaders(['Accept-Language' => 'de'])->get('/admin/no-such-page')->assertNotFound()
            ->assertInertia(fn ($page) => $page->where('locale', 'en'));
    }

    public function test_the_request_language_does_not_leak_into_the_next_request(): void
    {
        $this->actingAs($this->admin('ru'))->get('/admin/riders')->assertHeader('Content-Language', 'ru');
        $this->flushSession();
        auth()->forgetGuards();

        $this->withServerVariables(['HTTP_ACCEPT_LANGUAGE' => ''])->get('/login')->assertHeader('Content-Language', 'en');
    }
}
