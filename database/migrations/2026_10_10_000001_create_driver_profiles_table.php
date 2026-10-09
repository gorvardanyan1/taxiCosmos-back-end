<?php

use App\Enums\DriverAvailability;
use App\Enums\DriverVerificationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_profiles', function (Blueprint $table) {
            $table->id();
            // One profile per user; restrict so trips/earnings never lose their driver.
            $table->foreignId('user_id')->unique()->constrained()->restrictOnDelete();

            // Encrypted (randomised) ciphertext; exact lookups go through license_number_hash.
            $table->text('license_number')->nullable();
            $table->char('license_number_hash', 64)->nullable()->unique();
            $table->date('license_expiry')->nullable()->index();

            $table->string('verification_status', 32)->default(DriverVerificationStatus::Pending->value)->index();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->string('availability', 32)->default(DriverAvailability::Offline->value)->index();
            $table->timestamp('last_online_at')->nullable();

            // FK to zones is added by P5-T3, which creates the zones table.
            $table->unsignedBigInteger('home_zone_id')->nullable()->index();

            $table->decimal('rating_avg', 3, 2)->nullable();
            $table->unsignedInteger('rating_count')->default(0);

            $table->timestamps();
        });

        $this->checkIn('driver_profiles', 'verification_status', DriverVerificationStatus::cases());
        $this->checkIn('driver_profiles', 'availability', DriverAvailability::cases());
        DB::statement('ALTER TABLE driver_profiles ADD CONSTRAINT driver_profiles_rating_avg_check CHECK (rating_avg IS NULL OR (rating_avg >= 1 AND rating_avg <= 5))');
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_profiles');
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
