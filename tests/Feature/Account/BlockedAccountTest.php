<?php

namespace Tests\Feature\Account;

use App\Enums\AppClient;
use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * P3-T5: a blocked account (suspended, deactivated, pending deletion) that still holds a token gets 403 with a machine-readable
 * `code` on every endpoint except GET /me, logout, log out everywhere and support tickets, so the app can show its blocking
 * screen. (Suspending through the admin ends the sessions altogether, P4; this is the guard for a token that outlives that.)
 */
class BlockedAccountTest extends AccountTestCase
{
    /**
     * @return array<string, array{UserStatus, string, string}>
     */
    public static function blocked(): array
    {
        return [
            'suspended' => [UserStatus::Suspended, 'account_suspended', 'This account is suspended.'],
            'deactivated' => [UserStatus::Deactivated, 'account_deactivated', 'This account is not available.'],
            'pending deletion' => [UserStatus::PendingDeletion, 'account_pending_deletion', 'This account is not available.'],
        ];
    }

    /** A token issued while active, then the account is blocked without going through the observer (as a raw status flip would). */
    private function blockedRider(UserStatus $status): array
    {
        $user = $this->rider();
        $headers = $this->as($user);
        User::query()->whereKey($user->id)->update(['status' => $status->value]);

        return [$user, $headers];
    }

    #[DataProvider('blocked')]
    public function test_every_endpoint_but_the_allowed_ones_refuses_with_the_reason_code(UserStatus $status, string $code, string $message): void
    {
        [$user, $headers] = $this->blockedRider($status);

        $calls = [
            ['patch', '/api/v1/me', ['name' => 'X']],
            ['post', '/api/v1/me/phone/request', ['phone' => '+37499123456']],
            ['post', '/api/v1/me/phone/verify', ['phone' => '+37499123456', 'code' => '123456']],
            ['get', '/api/v1/saved-places', []],
            ['post', '/api/v1/saved-places', ['label' => 'home', 'name' => 'H', 'address' => 'A', 'lat' => 40, 'lng' => 44]],
            ['post', '/api/v1/devices', ['push_token' => 'ExponentPushToken[abcdefgh12]', 'platform' => 'ios']],
            ['get', '/api/v1/geo/autocomplete?q=abc', []],
            ['post', '/api/v1/trips/fare-estimate', []],
        ];

        foreach ($calls as [$method, $uri, $body]) {
            $this->json(strtoupper($method), $uri, $body, $headers)->assertForbidden()->assertExactJson(['message' => $message, 'code' => $code]);
        }

        $this->assertNotSame('X', $user->fresh()->name);
    }

    #[DataProvider('blocked')]
    public function test_a_blocked_account_can_still_read_me_and_sign_out(UserStatus $status): void
    {
        [$user, $headers] = $this->blockedRider($status);

        $this->getJson('/api/v1/me', $headers)->assertOk()->assertJsonPath('user.status', $status->value)->assertJsonPath('user.id', $user->id);

        $this->postJson('/api/v1/auth/logout', [], $headers)->assertNoContent();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_a_blocked_account_can_log_out_everywhere(): void
    {
        [$user, $headers] = $this->blockedRider(UserStatus::Suspended);
        $this->as($user, AppClient::Rider, 'phone-2');

        $this->postJson('/api/v1/auth/logout-all', [], $headers)->assertNoContent();

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_a_blocked_account_can_still_reach_the_support_endpoints(): void
    {
        Route::middleware(['api', 'auth:sanctum', 'mobile'])->prefix('api/v1/support')->group(function () {
            Route::get('tickets', fn () => response()->json(['ok' => true]))->name('support.tickets.index');
            Route::get('other', fn () => response()->json(['ok' => true]))->name('billing.other');
        });
        app('router')->getRoutes()->refreshNameLookups();
        [, $headers] = $this->blockedRider(UserStatus::Suspended);

        $this->getJson('/api/v1/support/tickets', $headers)->assertOk()->assertJson(['ok' => true]);
        $this->getJson('/api/v1/support/other', $headers)->assertForbidden()->assertJsonPath('code', 'account_suspended');
    }

    public function test_an_active_account_is_not_blocked_anywhere(): void
    {
        $headers = $this->as($this->rider());

        $this->getJson('/api/v1/saved-places', $headers)->assertOk();
        $this->patchJson('/api/v1/me', ['name' => 'Fine'], $headers)->assertOk();
    }

    public function test_the_suspension_through_the_admin_still_ends_the_sessions(): void
    {
        $user = $this->rider();
        $headers = $this->as($user);

        $user->forceFill(['status' => UserStatus::Suspended, 'suspension_reason' => 'x'])->save();

        $this->getJson('/api/v1/me', $headers)->assertUnauthorized();
    }

    public function test_a_refused_sign_in_of_a_suspended_account_carries_the_same_code(): void
    {
        $user = $this->rider();
        $user->forceFill(['status' => UserStatus::Suspended, 'suspension_reason' => 'x'])->save();
        $code = $this->requestCode('+37491210443');

        $this->postJson('/api/v1/auth/otp/verify', $this->verifyPayload('+37491210443', $code))->assertForbidden()
            ->assertExactJson(['message' => 'This account is suspended.', 'code' => 'account_suspended']);
    }

    public function test_an_admin_account_is_refused_with_its_own_code(): void
    {
        $admin = User::factory()->withPhone('+37491000009')->create();
        $admin->forceFill(['is_admin' => true])->save();
        $code = $this->requestCode('+37491000009');

        $this->postJson('/api/v1/auth/otp/verify', $this->verifyPayload('+37491000009', $code))->assertForbidden()->assertJsonPath('code', 'admin_account');
    }

    public function test_the_message_is_translated_and_the_code_is_not(): void
    {
        [, $headers] = $this->blockedRider(UserStatus::Suspended);

        $this->getJson('/api/v1/saved-places', $headers + ['Accept-Language' => 'ru'])->assertForbidden()
            ->assertExactJson(['message' => 'Этот аккаунт заблокирован.', 'code' => 'account_suspended']);
    }
}
