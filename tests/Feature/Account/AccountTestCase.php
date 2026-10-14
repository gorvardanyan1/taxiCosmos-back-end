<?php

namespace Tests\Feature\Account;

use App\Enums\AppClient;
use App\Models\User;
use App\Services\MobileAuth\MobileSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MobileAuthHelpers;
use Tests\TestCase;

/**
 * Shared steps for the P3-T5 account tests: real sessions through the real session service, requests with the bearer token.
 */
abstract class AccountTestCase extends TestCase
{
    use MobileAuthHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMobileAuth();
    }

    /**
     * Each request authenticates from its own token: Laravel remembers the user a guard resolved, which would carry one
     * request's caller into the next one in a test that switches between people.
     */
    public function call($method, $uri, $parameters = [], $cookies = [], $files = [], $server = [], $content = null)
    {
        app('auth')->forgetGuards();

        return parent::call($method, $uri, $parameters, $cookies, $files, $server, $content);
    }

    /** A signed-in session of the user in the given app; returns the bearer headers. */
    protected function as(User $user, AppClient $app = AppClient::Rider, string $device = 'phone-1'): array
    {
        return $this->bearer(app(MobileSessionService::class)->issue($user, $app, $device)->accessToken);
    }

    protected function rider(array $attributes = []): User
    {
        return User::factory()->rider()->withPhone('+37491210443')->create($attributes);
    }
}
