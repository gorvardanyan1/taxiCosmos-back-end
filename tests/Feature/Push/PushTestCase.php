<?php

namespace Tests\Feature\Push;

use App\Contracts\PushSender;
use App\Enums\AppClient;
use App\Models\Device;
use App\Models\User;
use App\Services\MobileAuth\MobileSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakePushSender;
use Tests\TestCase;

/**
 * Real PostgreSQL, real sessions and tokens; only the push service is faked. The queue is synchronous, so a push runs
 * as soon as it is queued (the tests about the gap between queueing and sending use Queue::fake()).
 */
abstract class PushTestCase extends TestCase
{
    use RefreshDatabase;

    protected FakePushSender $push;

    private int $phone = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->push = new FakePushSender;
        $this->app->instance(PushSender::class, $this->push);
    }

    protected function token(string $label): string
    {
        return "ExponentPushToken[{$label}-abcdefghij]";
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function driverUser(array $attributes = []): User
    {
        return User::factory()->driver()->withPhone('+3749160'.str_pad((string) ++$this->phone, 4, '0', STR_PAD_LEFT))->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function riderUser(array $attributes = []): User
    {
        return User::factory()->rider()->withPhone('+3749160'.str_pad((string) ++$this->phone, 4, '0', STR_PAD_LEFT))->create($attributes);
    }

    /** A real sign-in: the access token of a session on the given install. */
    protected function signedIn(User $user, AppClient $app, string $install = 'install-1'): string
    {
        app('auth')->forgetGuards();

        return app(MobileSessionService::class)->issue($user, $app, $install)->accessToken;
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function register(string $accessToken, string $pushToken, array $extra = [])
    {
        app('auth')->forgetGuards();

        return $this->postJson('/api/v1/devices', ['push_token' => $pushToken, 'platform' => 'android', ...$extra], ['Authorization' => 'Bearer '.$accessToken]);
    }

    protected function activeDevices(User $user): int
    {
        return Device::query()->active()->where('user_id', $user->id)->count();
    }
}
