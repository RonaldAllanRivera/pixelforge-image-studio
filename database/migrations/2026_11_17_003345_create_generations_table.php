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
        Schema::create('generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Pixelforge's id (gen_...), known once Pixelforge accepts the generation.
            $table->string('provider_id')->nullable()->unique();
            // Sent with every create attempt, so a retry can never create a second generation.
            $table->uuid('idempotency_key')->unique();
            $table->string('status');

            $table->text('prompt');
            $table->string('size');
            $table->string('quality');
            $table->json('reference_images');

            // USD per 1M tokens, fixed by Pixelforge when it accepted the generation.
            $table->json('rates')->nullable();

            // Money is in nanodollars (1 USD = 1,000,000,000). "Cost" is what Pixelforge
            // charges us; "charge" is what the user pays, cost plus markup.
            $table->bigInteger('estimated_cost_nano');
            $table->bigInteger('estimated_charge_nano');
            $table->bigInteger('max_cost_nano');
            $table->bigInteger('max_charge_nano');

            // Filled in once the generation finishes: actual tokens, cost and charge (0 if it failed).
            $table->integer('input_text_tokens')->nullable();
            $table->integer('input_image_tokens')->nullable();
            $table->integer('output_tokens')->nullable();
            $table->integer('reasoning_tokens')->nullable();
            $table->bigInteger('provider_cost_nano')->nullable();
            $table->bigInteger('charge_nano')->nullable();

            $table->text('image_url')->nullable();
            $table->timestamp('image_url_expires_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();

            $table->unsignedSmallInteger('submit_attempts')->default(0);
            // Pixelforge's timestamps. GET /v1/usage bills a generation on the UTC day it completed.
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']); // a user's history, newest first
            $table->index(['status', 'updated_at']);  // finding generations that are stuck
            $table->index('completed_at');            // the daily usage report
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('generations');
    }
};
