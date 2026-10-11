<?php

namespace Tests\Feature\MobileAuth;

use App\Models\OtpCode;
use App\Models\RefreshToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schedule;
use Tests\Support\MobileAuthHelpers;
use Tests\TestCase;

class PruneMobileAuthTest extends TestCase
{
    use MobileAuthHelpers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpMobileAuth();
    }

    public function test_it_removes_only_codes_and_refresh_tokens_that_are_long_expired(): void
    {
        $this->travelTo('2026-10-11 10:00:00');
        $this->signIn('+37491440221');
        $fresh = RefreshToken::query()->sole();
        $freshCode = OtpCode::query()->sole();

        OtpCode::query()->forceCreate(['phone_hash' => str_repeat('a', 64), 'code_hash' => str_repeat('b', 64), 'expires_at' => now()->subDays(2)]);
        OtpCode::query()->forceCreate(['phone_hash' => str_repeat('c', 64), 'code_hash' => str_repeat('d', 64), 'expires_at' => now()->subHours(2)]);
        RefreshToken::query()->forceCreate([
            'user_id' => $fresh->user_id, 'app' => 'rider', 'device_id' => 'old-device', 'family_id' => (string) \Str::uuid(),
            'token_hash' => str_repeat('e', 64), 'expires_at' => now()->subDays(8),
        ]);
        RefreshToken::query()->forceCreate([
            'user_id' => $fresh->user_id, 'app' => 'rider', 'device_id' => 'recent-device', 'family_id' => (string) \Str::uuid(),
            'token_hash' => str_repeat('f', 64), 'expires_at' => now()->subDays(3),
        ]);

        $this->artisan('mobile-auth:prune')->assertSuccessful();

        $this->assertSame(2, OtpCode::query()->count());
        $this->assertTrue(OtpCode::query()->whereKey($freshCode->id)->exists());
        $this->assertSame(2, RefreshToken::query()->count());
        $this->assertSame(['recent-device', $fresh->device_id], RefreshToken::query()->orderBy('id', 'desc')->pluck('device_id')->all());
    }

    public function test_the_pruning_jobs_are_scheduled_daily_without_overlap(): void
    {
        $events = collect(Schedule::events())->filter(fn ($e) => str_contains($e->command, 'mobile-auth:prune') || str_contains($e->command, 'sanctum:prune-expired'));

        $this->assertCount(2, $events);
        foreach ($events as $event) {
            $this->assertTrue($event->withoutOverlapping);
            $this->assertTrue($event->onOneServer);
            $this->assertSame($event->expression === '30 3 * * *' || $event->expression === '40 3 * * *', true);
        }
    }
}
