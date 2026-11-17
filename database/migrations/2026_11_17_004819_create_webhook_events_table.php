<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('webhook_events', function (Blueprint $table) {
            $table->id();
            // Pixelforge's event id (evt_...). Unique, so a duplicate delivery can never be applied twice.
            $table->string('event_id')->unique();
            $table->string('type');
            $table->foreignId('generation_id')->nullable()->constrained()->nullOnDelete();
            $table->json('payload');
            $table->unsignedSmallInteger('delivery_attempt')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('webhook_events');
    }
};
