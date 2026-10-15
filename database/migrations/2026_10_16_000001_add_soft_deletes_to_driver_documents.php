<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A document the driver replaces (a pending or rejected one) or that belonged to a vehicle they removed is kept as a
        // row for the audit trail but no longer counts for verification or shows in lists.
        Schema::table('driver_documents', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('driver_documents', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};
