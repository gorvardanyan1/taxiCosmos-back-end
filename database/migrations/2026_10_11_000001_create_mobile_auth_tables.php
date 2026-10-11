<?php

use App\Enums\AppClient;
use App\Enums\SocialProvider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One-time phone codes. Only an HMAC of the code is stored, and a phone has at most one live code.
        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->char('phone_hash', 64)->index();
            $table->char('code_hash', 64);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('social_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 16);
            $table->string('provider_user_id', 255);
            $table->string('email')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            // A person links at most one account per provider.
            $table->unique(['user_id', 'provider']);
        });

        // Refresh tokens: only a SHA-256 of the 64-byte secret is stored. A family is one login (one
        // device); every rotation stays in it, so replaying a used token can revoke the whole chain.
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('access_token_id')->nullable()->constrained('personal_access_tokens')->nullOnDelete();
            $table->string('app', 16);
            $table->string('device_id', 100);
            $table->uuid('family_id')->index();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'device_id']);
        });

        $providers = implode(', ', array_map(fn (SocialProvider $p) => "'{$p->value}'", SocialProvider::cases()));
        $apps = implode(', ', array_map(fn (AppClient $a) => "'{$a->value}'", AppClient::cases()));

        DB::statement("ALTER TABLE social_accounts ADD CONSTRAINT social_accounts_provider_check CHECK (provider IN ({$providers}))");
        DB::statement("ALTER TABLE refresh_tokens ADD CONSTRAINT refresh_tokens_app_check CHECK (app IN ({$apps}))");
        DB::statement('ALTER TABLE otp_codes ADD CONSTRAINT otp_codes_attempts_check CHECK (attempts >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('refresh_tokens');
        Schema::dropIfExists('social_accounts');
        Schema::dropIfExists('otp_codes');
    }
};
