<?php

use App\Enums\GenerationStatus;
use App\Enums\WaitingReason;
use App\Http\Resources\GenerationDetailResource;
use App\Http\Resources\GenerationResource;
use App\Models\Generation;
use Tests\Fixtures\Pixelforge;

/**
 * @return array<string, mixed>
 */
function cardFor(Generation $generation): array
{
    return (new GenerationResource($generation))->resolve();
}

test('formats a pending generation', function () {
    $card = cardFor(Generation::factory()->create());

    expect($card)
        ->status->toBe('pending')
        ->status_label->toBe('Submitting')
        ->size_label->toBe('Square')
        ->quality_label->toBe('Medium')
        ->estimated_charge->toBe('$0.0722')
        ->maximum_charge->toBe('$0.0863')
        ->actual_charge->toBeNull()
        ->failure_reason->toBeNull();
});

test('never shows the maximum below the real cap', function () {
    $card = cardFor(Generation::factory()->create(['max_charge_nano' => 340_025_000]));

    expect($card['maximum_charge'])->toBe('$0.3401');
});

test('shows why a pending generation waits', function () {
    $card = cardFor(Generation::factory()->create(['waiting_reason' => WaitingReason::FreeSlot]));

    expect($card['status_label'])->toBe('Waiting for a free slot');
});

test('knows which statuses are final, so the page stops polling', function (GenerationStatus $status, bool $final) {
    expect(cardFor(Generation::factory()->create(['status' => $status]))['is_final'])->toBe($final);
})->with([
    'pending' => [GenerationStatus::Pending, false],
    'queued' => [GenerationStatus::Queued, false],
    'processing' => [GenerationStatus::Processing, false],
    'succeeded' => [GenerationStatus::Succeeded, true],
    'failed' => [GenerationStatus::Failed, true],
]);

test('marks an unfinished generation with no news for 15 minutes as stalled, so the page stops polling it', function () {
    $stalled = cardFor(Generation::factory()->create(['status' => GenerationStatus::Processing, 'updated_at' => now()->subMinutes(16)]));
    $recent = cardFor(Generation::factory()->create(['status' => GenerationStatus::Processing, 'updated_at' => now()->subMinutes(5)]));
    $finished = cardFor(Generation::factory()->create(['status' => GenerationStatus::Succeeded, 'updated_at' => now()->subHour()]));

    expect($stalled['is_stalled'])->toBeTrue()
        ->and($recent['is_stalled'])->toBeFalse()
        ->and($finished['is_stalled'])->toBeFalse();
});

test('hides an expired image instead of showing a broken one', function () {
    $expired = cardFor(Generation::factory()->create([
        'image_url' => 'https://images.example/fox.png',
        'image_url_expires_at' => now()->subHour(),
    ]));
    $never = cardFor(Generation::factory()->create());
    $fresh = cardFor(Generation::factory()->create([
        'image_url' => 'https://images.example/fox.png',
        'image_url_expires_at' => now()->addHour(),
    ]));

    expect($expired)->image_url->toBeNull()->image_expired->toBeTrue()
        ->and($never)->image_url->toBeNull()->image_expired->toBeFalse()
        ->and($fresh)->image_url->toBe('https://images.example/fox.png')->image_expired->toBeFalse();
});

test('explains failures in plain words, and never charges them', function (?string $code, string $reason) {
    $card = cardFor(Generation::factory()->create([
        'status' => GenerationStatus::Failed,
        'error_code' => $code,
        'error_message' => 'raw provider message',
    ]));

    expect($card)
        ->status_label->toBe('Failed')
        ->failure_reason->toBe($reason)
        ->actual_charge->toBe('Not charged');
})->with([
    'content policy' => ['content_policy_violation', "Pixelforge's content policy blocked this prompt."],
    'daily limit' => ['daily_limit_exceeded', "Pixelforge's daily limit is reached. Try again after midnight UTC."],
    'overloaded' => ['overloaded', 'Pixelforge was too busy to take it, even after 5 tries.'],
    'failed after accepting' => ['generation_failed', "Pixelforge couldn't generate this image."],
    'our own mistake' => ['validation_failed', 'Something went wrong while creating this image.'],
    'no code' => [null, 'Something went wrong while creating this image.'],
]);

test('shows the cost math for a finished generation', function () {
    $generation = Generation::factory()->create([
        'status' => GenerationStatus::Succeeded,
        'provider_id' => 'gen_test',
        'rates' => Pixelforge::load('pricing')['rates'],
        'quoted_usage' => [
            'estimated' => ['input_text_tokens' => 3, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 1100],
            'maximum' => ['input_text_tokens' => 4, 'input_image_tokens' => 0, 'output_tokens' => 1100, 'reasoning_tokens' => 2000],
        ],
        'input_text_tokens' => 3,
        'input_image_tokens' => 0,
        'output_tokens' => 1100,
        'reasoning_tokens' => 900,
        'provider_cost_nano' => 55_265_000,
        'charge_nano' => 69_081_250,
    ]);

    $detail = (new GenerationDetailResource($generation))->resolve();

    expect($detail['tokens'][0])->toBe([
        'label' => 'Text', 'estimated' => 3, 'maximum' => 4, 'actual' => 3, 'rate' => '$5.00', 'cost' => '$0.000015000',
    ])
        ->and($detail['tokens'][3]['cost'])->toBe('$0.011250000')
        ->and($detail['totals'])->toBe([
            'provider_cost' => '$0.055265000',
            'fee' => '$0.013816250',
            'charge' => '$0.069081250',
        ])
        ->and($detail['actual_charge'])->toBe('$0.0691')
        ->and($detail['provider_id'])->toBe('gen_test');
});

test('leaves the cost math empty until Pixelforge reports it', function () {
    $detail = (new GenerationDetailResource(Generation::factory()->create()))->resolve();

    expect($detail['tokens'][2])->toBe([
        'label' => 'Image output', 'estimated' => null, 'maximum' => null, 'actual' => null, 'rate' => null, 'cost' => null,
    ])->and($detail['totals'])->toBeNull();
});
