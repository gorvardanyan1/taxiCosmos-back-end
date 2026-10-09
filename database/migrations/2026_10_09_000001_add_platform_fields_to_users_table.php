<?php

use App\Enums\UserStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One users row per person: capability flags (is_rider / is_driver / is_admin)
     * instead of a user_type enum, so a person can ride and drive on one account.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Mobile users sign up by phone OTP or social login and may have no name/email/password yet.
            $table->string('name')->nullable()->change();
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();

            // Encrypted (randomised) ciphertext; lookups go through phone_hash (HMAC blind index).
            $table->text('phone')->nullable()->after('email');
            $table->char('phone_hash', 64)->nullable()->unique()->after('phone');

            $table->boolean('is_rider')->default(false)->after('password');
            $table->boolean('is_driver')->default(false)->after('is_rider');
            $table->boolean('is_admin')->default(false)->after('is_driver');

            $table->string('status', 32)->default(UserStatus::Active->value)->index()->after('is_admin');
            $table->text('suspension_reason')->nullable()->after('status');

            $table->string('locale', 10)->nullable()->after('suspension_reason');
            $table->string('timezone', 64)->nullable()->after('locale');

            $table->decimal('rider_rating_avg', 3, 2)->nullable()->after('timezone');
            $table->unsignedInteger('rider_rating_count')->default(0)->after('rider_rating_avg');

            $table->timestamp('last_login_at')->nullable()->after('remember_token');
            $table->softDeletes();
        });

        $statuses = implode(', ', array_map(fn (UserStatus $s) => "'{$s->value}'", UserStatus::cases()));

        DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ({$statuses}))");
        DB::statement('ALTER TABLE users ADD CONSTRAINT users_rider_rating_avg_check CHECK (rider_rating_avg IS NULL OR (rider_rating_avg >= 1 AND rider_rating_avg <= 5))');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_rider_rating_avg_check');
        DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check');

        Schema::table('users', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropUnique(['phone_hash']);
            $table->dropIndex(['status']);
            $table->dropColumn([
                'phone', 'phone_hash', 'is_rider', 'is_driver', 'is_admin', 'status', 'suspension_reason',
                'locale', 'timezone', 'rider_rating_avg', 'rider_rating_count', 'last_login_at',
            ]);

            $table->string('name')->nullable(false)->change();
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });
    }
};
