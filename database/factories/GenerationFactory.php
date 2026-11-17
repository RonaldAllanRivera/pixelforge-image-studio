<?php

namespace Database\Factories;

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Generation>
 */
class GenerationFactory extends Factory
{
    /**
     * A pending generation, saved but not yet sent to Pixelforge.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'idempotency_key' => (string) Str::uuid(),
            'status' => GenerationStatus::Pending,
            'prompt' => 'A red fox',
            'size' => ImageSize::Square,
            'quality' => ImageQuality::Medium,
            'reference_images' => [],
            // The real quote for this prompt, size and quality at Pixelforge's published prices.
            'estimated_cost_nano' => 57_765_000,
            'estimated_charge_nano' => 72_206_250,
            'max_cost_nano' => 69_020_000,
            'max_charge_nano' => 86_275_000,
        ];
    }
}
