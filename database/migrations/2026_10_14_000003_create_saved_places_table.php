<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_places', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('label', 8);
            $table->string('name', 80);
            $table->string('address', 255);
            $table->timestamps();

            $table->index('user_id');
        });

        DB::statement('ALTER TABLE saved_places ADD COLUMN location geography(Point, 4326) NOT NULL');
        DB::statement("ALTER TABLE saved_places ADD CONSTRAINT saved_places_label_check CHECK (label IN ('home', 'work', 'other'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_places');
    }
};
