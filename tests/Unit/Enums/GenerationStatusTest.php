<?php

use App\Enums\GenerationStatus;

test('only succeeded and failed are final', function () {
    expect(GenerationStatus::Pending->isFinal())->toBeFalse()
        ->and(GenerationStatus::Queued->isFinal())->toBeFalse()
        ->and(GenerationStatus::Processing->isFinal())->toBeFalse()
        ->and(GenerationStatus::Succeeded->isFinal())->toBeTrue()
        ->and(GenerationStatus::Failed->isFinal())->toBeTrue();
});

test('can move forward, never back, and never out of a final status', function (GenerationStatus $from, GenerationStatus $to, bool $allowed) {
    expect($from->canMoveTo($to))->toBe($allowed);
})->with([
    'pending to queued' => [GenerationStatus::Pending, GenerationStatus::Queued, true],
    'pending straight to succeeded (the webhook beat the POST response)' => [GenerationStatus::Pending, GenerationStatus::Succeeded, true],
    'queued to processing' => [GenerationStatus::Queued, GenerationStatus::Processing, true],
    'processing to succeeded' => [GenerationStatus::Processing, GenerationStatus::Succeeded, true],
    'processing to failed' => [GenerationStatus::Processing, GenerationStatus::Failed, true],
    'processing back to queued (a late POST response)' => [GenerationStatus::Processing, GenerationStatus::Queued, false],
    'processing to processing (a duplicate event)' => [GenerationStatus::Processing, GenerationStatus::Processing, false],
    'succeeded to processing (an out-of-order event)' => [GenerationStatus::Succeeded, GenerationStatus::Processing, false],
    'succeeded to succeeded (a duplicate event)' => [GenerationStatus::Succeeded, GenerationStatus::Succeeded, false],
    'succeeded to failed' => [GenerationStatus::Succeeded, GenerationStatus::Failed, false],
    'failed to succeeded' => [GenerationStatus::Failed, GenerationStatus::Succeeded, false],
]);
