<?php

namespace App\Console\Commands;

use App\Models\OtpCode;
use App\Models\RefreshToken;
use Illuminate\Console\Command;

class PruneMobileAuth extends Command
{
    protected $signature = 'mobile-auth:prune';

    protected $description = 'Delete expired one-time codes and refresh tokens that can no longer be used';

    public function handle(): int
    {
        // Kept a while after expiry so a replayed old refresh token is still recognised as reuse.
        $codes = OtpCode::query()->where('expires_at', '<', now()->subDay())->delete();
        $tokens = RefreshToken::query()->where('expires_at', '<', now()->subDays(7))->delete();

        $this->info("Removed {$codes} one-time codes and {$tokens} refresh tokens.");

        return self::SUCCESS;
    }
}
