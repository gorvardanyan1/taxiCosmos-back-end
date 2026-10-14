<?php

use App\Enums\AppClient;
use App\Enums\DevicePlatform;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('app', 16);
            $table->string('platform', 16);
            // One row per physical token: registering a token that belongs to another user moves the row (shared phones).
            $table->string('push_token', 255)->unique();
            // The install id the app sends at sign-in (refresh_tokens.device_id): ties a registration to a session, so logging
            // out, or someone else signing in on the same install, ends it.
            $table->string('device_id', 128)->nullable();
            $table->string('locale', 8)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'revoked_at']);
            $table->index(['app', 'device_id']);
        });

        $this->checkIn('devices', 'app', AppClient::cases());
        $this->checkIn('devices', 'platform', DevicePlatform::cases());

        // Expo push tickets waiting for their receipt, which is where "this token is no longer registered" is reported.
        Schema::create('push_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('ticket_id', 64)->unique();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_tickets');
        Schema::dropIfExists('devices');
    }

    /**
     * @param  list<BackedEnum>  $cases
     */
    private function checkIn(string $table, string $column, array $cases): void
    {
        $values = implode(', ', array_map(fn (BackedEnum $case) => "'{$case->value}'", $cases));

        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$column} IN ({$values}))");
    }
};
