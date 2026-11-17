<?php

use App\Enums\GenerationStatus;
use App\Pixelforge\GenerationSnapshot;
use App\Pricing\TokenUsage;
use Tests\Fixtures\Pixelforge;

test('reads a queued generation, which already has its rates', function () {
    $snapshot = GenerationSnapshot::fromArray(Pixelforge::load('generation-queued'));

    expect($snapshot->id)->toBe('gen_01M7KDZJNGSRKY7HM2E2PMEGVA')
        ->and($snapshot->status)->toBe(GenerationStatus::Queued)
        ->and($snapshot->metadata)->toBe(['purpose' => 'fixture-capture'])
        ->and($snapshot->rates)->toBe([
            'input_text_tokens' => '5.00',
            'input_image_tokens' => '10.00',
            'output_tokens' => '40.00',
            'reasoning_tokens' => '12.50',
        ])
        ->and($snapshot->usage)->toBeNull()
        ->and($snapshot->imageUrl)->toBeNull()
        ->and($snapshot->startedAt)->toBeNull()
        ->and($snapshot->completedAt)->toBeNull();
});

test('reads a succeeded generation with its usage, image and timestamps', function () {
    $snapshot = GenerationSnapshot::fromArray(Pixelforge::load('generation-succeeded'));

    expect($snapshot->status)->toBe(GenerationStatus::Succeeded)
        ->and($snapshot->usage)->toEqual(new TokenUsage(inputText: 10, inputImage: 0, output: 280, reasoning: 480))
        ->and($snapshot->imageUrl)->toStartWith('https://pixelforge.promptedit.dev/images/gen_01M7KAQH2VCJ8PYCW8AV4D9PHV.png')
        ->and($snapshot->imageUrlExpiresAt?->toIso8601ZuluString())->toBe('2026-11-17T23:23:17Z')
        ->and($snapshot->startedAt?->toIso8601ZuluString())->toBe('2026-11-16T23:23:00Z')
        ->and($snapshot->completedAt?->toIso8601ZuluString())->toBe('2026-11-16T23:23:17Z')
        ->and($snapshot->errorCode)->toBeNull();
});

test('reads a failed generation with its error', function () {
    $snapshot = GenerationSnapshot::fromArray(Pixelforge::load('generation-failed'));

    expect($snapshot->status)->toBe(GenerationStatus::Failed)
        ->and($snapshot->usage)->toBeNull()
        ->and($snapshot->errorCode)->toBe('generation_failed')
        ->and($snapshot->errorMessage)->toBe('The model could not produce an image for this prompt. You were not charged.')
        ->and($snapshot->completedAt?->toIso8601ZuluString())->toBe('2026-11-17T00:20:08Z');
});

test('reads the generation inside a webhook event the same way', function () {
    $event = Pixelforge::load('event-generation-succeeded');

    $snapshot = GenerationSnapshot::fromArray($event['data']['object']);

    expect($snapshot->id)->toBe('gen_01M7KE16V4095Y7FRGB0NW8ZC4')
        ->and($snapshot->status)->toBe(GenerationStatus::Succeeded)
        ->and($snapshot->usage?->inputImage)->toBe(765);
});

test('refuses something that is not a generation', function (array $object) {
    GenerationSnapshot::fromArray($object);
})->with([
    'no id' => [['status' => 'queued']],
    'unknown status' => [['id' => 'gen_1', 'status' => 'exploded']],
    'our local pending status' => [['id' => 'gen_1', 'status' => 'pending']],
])->throws(InvalidArgumentException::class);
