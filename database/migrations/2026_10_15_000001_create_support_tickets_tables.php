<?php

use App\Enums\SupportCategory;
use App\Enums\SupportPriority;
use App\Enums\SupportStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            // TKT-00042: derived from the id, so it is unique and never needs a second write.
            $table->string('code', 16)->storedAs("'TKT-' || lpad(id::text, 5, '0')")->unique();
            $table->foreignId('requester_id')->constrained('users')->restrictOnDelete();
            // The app the ticket came from; one person can ride and drive.
            $table->string('requester_type', 16);
            $table->foreignId('trip_id')->nullable()->constrained('trips')->restrictOnDelete();
            $table->string('subject', 160);
            $table->string('category', 32);
            $table->string('priority', 16)->default(SupportPriority::Normal->value);
            $table->string('status', 32)->default(SupportStatus::Open->value);
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamp('last_message_at')->nullable();
            // The app's retry key for creating this ticket: the same key never creates a second one.
            $table->string('idempotency_key', 64)->nullable();
            $table->timestamps();

            $table->unique(['requester_id', 'idempotency_key']);
            $table->index(['status', 'priority']);
            $table->index(['requester_id', 'requester_type', 'last_message_at']);
            $table->index('assigned_to');
            $table->index('trip_id');
        });

        $this->check('support_tickets', 'requester_type', ['rider', 'driver']);
        $this->check('support_tickets', 'category', array_column(SupportCategory::cases(), 'value'));
        $this->check('support_tickets', 'priority', array_column(SupportPriority::cases(), 'value'));
        $this->check('support_tickets', 'status', array_column(SupportStatus::cases(), 'value'));

        Schema::create('support_ticket_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users')->restrictOnDelete();
            $table->text('body');
            // Seen by staff only; the mobile endpoints never return these.
            $table->boolean('is_internal_note')->default(false);
            $table->jsonb('attachments')->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['ticket_id', 'author_id', 'idempotency_key']);
            $table->index(['ticket_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }

    /**
     * @param  list<string>  $values
     */
    private function check(string $table, string $column, array $values): void
    {
        $list = implode(', ', array_map(fn (string $value) => "'{$value}'", $values));
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_{$column}_check CHECK ({$column} IN ({$list}))");
    }
};
