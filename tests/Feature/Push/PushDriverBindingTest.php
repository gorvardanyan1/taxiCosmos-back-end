<?php

namespace Tests\Feature\Push;

use App\Contracts\PushSender;
use App\Data\PushMessage;
use App\Enums\AppClient;
use App\Enums\PushType;
use App\Models\Device;
use App\Models\User;
use App\Services\MobileAuth\MobileSessionService;
use App\Services\Push\ExpoPushSender;
use App\Services\Push\LogPushSender;
use App\Services\Push\PushNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use ReflectionProperty;
use RuntimeException;
use Tests\TestCase;

/**
 * P8-T4: "Push sending is faked in tests and logged (not sent) in local/staging unless explicitly enabled".
 */
class PushDriverBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_outside_production_the_default_is_to_log_and_send_nothing(): void
    {
        $this->assertSame('log', config('services.push.driver'));
        $this->assertInstanceOf(LogPushSender::class, app(PushSender::class));
    }

    public function test_expo_is_used_only_when_it_is_switched_on_and_gets_its_access_token(): void
    {
        config(['services.push.driver' => 'expo', 'services.push.expo.access_token' => 'tok-123']);

        $sender = app(PushSender::class);

        $this->assertInstanceOf(ExpoPushSender::class, $sender);
        $this->assertSame('tok-123', (new ReflectionProperty($sender, 'accessToken'))->getValue($sender));
    }

    public function test_production_has_no_default_and_an_unknown_driver_fails_loudly(): void
    {
        $this->assertStringContainsString("env('PUSH_DRIVER', env('APP_ENV') === 'production' ? null : 'log')", (string) file_get_contents(base_path('config/services.php')));

        foreach ([null, 'fcm', ''] as $driver) {
            config(['services.push.driver' => $driver]);

            try {
                app(PushSender::class);
                $this->fail('driver '.var_export($driver, true).' must be refused');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('PUSH_DRIVER must be set to expo or log', $e->getMessage());
            }
        }
    }

    public function test_the_whole_pipeline_on_the_default_driver_sends_nothing_over_the_network_and_logs_the_push(): void
    {
        Http::fake();
        Log::spy();
        $user = User::factory()->driver()->withPhone('+37491605555')->create(['locale' => 'ru']);
        $access = app(MobileSessionService::class)->issue($user, AppClient::Driver, 'install-1')->accessToken;
        app('auth')->forgetGuards();
        $this->postJson('/api/v1/devices', ['push_token' => 'ExponentPushToken[abcdefghij0123]', 'platform' => 'ios'], ['Authorization' => 'Bearer '.$access])->assertCreated();

        app(PushNotifier::class)->notify($user, PushType::TripOffer, ['offer_id' => 1, 'trip_id' => 2]);

        Http::assertNothingSent();
        Log::shouldHaveReceived('info')->once()->withArgs(function ($message, $context) {
            return str_starts_with($message, 'Push notification (not sent')
                && $context['title'] === 'Новый заказ'
                && $context['data'] === ['type' => 'trip_offer', 'offer_id' => 1, 'trip_id' => 2]
                && $context['token'] === LogPushSender::fingerprint('ExponentPushToken[abcdefghij0123]')
                && ! str_contains(json_encode($context), 'ExponentPushToken');
        });
        $this->assertSame(1, Device::query()->active()->count(), 'a logged push is not a failure: the device stays');
    }

    public function test_the_log_sender_accepts_every_message_and_has_no_receipts(): void
    {
        $sender = new LogPushSender;

        $results = $sender->send([new PushMessage('ExponentPushToken[abcdefghij01]', 't', 'b', ['type' => 'x']), new PushMessage('ExponentPushToken[abcdefghij02]', 't', 'b', ['type' => 'x'])]);

        $this->assertSame([true, true], array_map(fn ($r) => $r->ok, $results));
        $this->assertSame([], $sender->receipts(['a']));
        $this->assertNotSame(LogPushSender::fingerprint('ExponentPushToken[abcdefghij01]'), LogPushSender::fingerprint('ExponentPushToken[abcdefghij02]'));
    }
}
