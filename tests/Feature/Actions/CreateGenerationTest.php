<?php

use App\Actions\CreateGeneration;
use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Jobs\SubmitGeneration;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Pixelforge;

beforeEach(function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(Pixelforge::load('pricing'))]);
    Queue::fake();
    $this->user = User::factory()->create();
});

test('saves a pending generation with its estimate and maximum price', function () {
    $generation = app(CreateGeneration::class)->handle($this->user, 'A red fox', ImageSize::Square, ImageQuality::Medium, []);

    expect($generation->fresh())
        ->user_id->toBe($this->user->id)
        ->status->toBe(GenerationStatus::Pending)
        ->prompt->toBe('A red fox')
        ->estimated_cost_nano->toBe(57_765_000)
        ->estimated_charge_nano->toBe(72_206_250)
        ->max_cost_nano->toBe(69_020_000)
        ->max_charge_nano->toBe(86_275_000);
});

test('stores the quoted token counts, so the detail page can compare them with the actual ones', function () {
    $generation = app(CreateGeneration::class)->handle($this->user, 'A red fox', ImageSize::Square, ImageQuality::Medium, []);

    expect($generation->fresh()->quoted_usage)->toBe([
        'estimated' => ['input_text_tokens' => 3, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1100],
        'maximum' => ['input_text_tokens' => 4, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 2000],
    ]);
});

test('prices reference images into the quote', function () {
    $generation = app(CreateGeneration::class)->handle($this->user, 'A red fox', ImageSize::Square, ImageQuality::Medium, [
        'https://example.com/a.png',
        'https://example.com/b.png',
    ]);

    // 2 × 765 input image tokens at $10 per 1M tokens adds exactly $0.0153 of cost.
    expect($generation->max_cost_nano)->toBe(69_020_000 + 15_300_000)
        ->and($generation->reference_images)->toBe(['https://example.com/a.png', 'https://example.com/b.png']);
});

test('sends it to Pixelforge in the background', function () {
    $generation = app(CreateGeneration::class)->handle($this->user, 'A red fox', ImageSize::Square, ImageQuality::Medium, []);

    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($generation));
});

test('gives every generation its own idempotency key', function () {
    $first = app(CreateGeneration::class)->handle($this->user, 'A red fox', ImageSize::Square, ImageQuality::Medium, []);
    $second = app(CreateGeneration::class)->handle($this->user, 'A red fox', ImageSize::Square, ImageQuality::Medium, []);

    expect($first->idempotency_key)->toBeString()->not->toBe($second->idempotency_key);
});
