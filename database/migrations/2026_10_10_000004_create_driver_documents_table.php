<?php

use App\Enums\DriverDocumentStatus;
use App\Enums\DriverDocumentType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('driver_documents', function (Blueprint $table) {
            $table->id();
            // Restrict: a document is evidence for a verification decision, never cascade-deleted.
            $table->foreignId('driver_id')->constrained('driver_profiles')->restrictOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->restrictOnDelete();
            $table->string('type', 32);

            // Encrypted (randomised) ciphertext. No blind index: nobody searches by document number.
            $table->text('document_number')->nullable();

            // Path on the private documents disk (config taxikosmos.documents.disk), never a URL.
            $table->string('file_path', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->date('expires_at')->nullable();

            $table->string('status', 32)->default(DriverDocumentStatus::Pending->value);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('rejection_reason')->nullable();

            $table->timestamps();

            $table->index(['driver_id', 'type']);
            $table->index(['status', 'expires_at']);
        });

        $types = implode(', ', array_map(fn (DriverDocumentType $t) => "'{$t->value}'", DriverDocumentType::cases()));
        $statuses = implode(', ', array_map(fn (DriverDocumentStatus $s) => "'{$s->value}'", DriverDocumentStatus::cases()));

        DB::statement("ALTER TABLE driver_documents ADD CONSTRAINT driver_documents_type_check CHECK (type IN ({$types}))");
        DB::statement("ALTER TABLE driver_documents ADD CONSTRAINT driver_documents_status_check CHECK (status IN ({$statuses}))");
        // Only vehicle papers may name a vehicle; driver papers (license, id card, background check) must not.
        DB::statement("ALTER TABLE driver_documents ADD CONSTRAINT driver_documents_vehicle_check CHECK (type IN ('vehicle_registration', 'insurance') OR vehicle_id IS NULL)");
        // A rejection always carries its reason.
        DB::statement("ALTER TABLE driver_documents ADD CONSTRAINT driver_documents_rejection_reason_check CHECK (status <> 'rejected' OR (rejection_reason IS NOT NULL AND rejection_reason <> ''))");
    }

    public function down(): void
    {
        Schema::dropIfExists('driver_documents');
    }
};
