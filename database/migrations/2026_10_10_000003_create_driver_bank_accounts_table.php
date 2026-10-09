<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('driver_id')->constrained('driver_profiles')->restrictOnDelete();
            $table->string('account_holder');
            // IBAN or local account number, encrypted. No blind index: nothing searches by it.
            $table->text('account_number');
            // Last 4 characters for the masked "•••• 4821" display without decrypting.
            $table->char('account_last4', 4);
            $table->string('bank_name');
            $table->string('swift', 11)->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
            // Soft deletes: payouts keep referencing an account the driver removed.
            $table->softDeletes();

            $table->index('driver_id');
        });

        // At most one default (non-deleted) bank account per driver.
        DB::statement('CREATE UNIQUE INDEX driver_bank_accounts_one_default_per_driver ON driver_bank_accounts (driver_id) WHERE is_default AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_bank_accounts');
    }
};
