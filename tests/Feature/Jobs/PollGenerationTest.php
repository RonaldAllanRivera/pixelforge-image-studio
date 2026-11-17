<?php

use App\Enums\GenerationStatus;
use App\Jobs\PollGeneration;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Pixelforge;

beforeEach(function () {
    Queue::fake();
    $this->generation = Generation::factory()->create([
        'status' => GenerationStatus::Queued,
        'provider_id' => 'gen_01M7KAQH2VCJ8PYCW8AV4D9PHV',
    ]);
    $this->url = 'pixelforge.test/v1/generations/gen_01M7KAQH2VCJ8PYCW8AV4D9PHV';
});

/**
 * Run one poll, the way a queue worker would.
 */
function runPollGeneration(Generation $generation, int $poll = 1): PollGeneration
{
    $job = (new PollGeneration($generation, $poll))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

test('asks Pixelforge and records the result', function () {
    Http::fake([$this->url => Http::response(Pixelforge::load('generation-succeeded'))]);

    runPollGeneration($this->generation);

    expect($this->generation->fresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->charge_nano->toBe(21_562_500);
    Queue::assertNotPushed(PollGeneration::class);
});

test('polls again later while the generation is still running', function (int $poll, int $wait) {
    Http::fake([$this->url => Http::response([...Pixelforge::load('generation-succeeded'), 'status' => 'processing', 'usage' => null, 'image_url' => null])]);

    runPollGeneration($this->generation, $poll);

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Processing);
    Queue::assertPushed(PollGeneration::class, fn (PollGeneration $job) => $job->poll === $poll + 1 && $job->delay === $wait);
})->with([
    'after the 1st poll' => [1, 30],
    'after the 2nd poll' => [2, 45],
    'after the 3rd poll' => [3, 60],
    'after the 10th poll' => [10, 60],
]);

test('stops polling 15 minutes after the generation was created, leaving it to reconcile', function () {
    $this->travel(16)->minutes();
    Http::fake([$this->url => Http::response([...Pixelforge::load('generation-succeeded'), 'status' => 'processing', 'usage' => null, 'image_url' => null])]);

    runPollGeneration($this->generation, 12);

    Queue::assertNotPushed(PollGeneration::class);
});

test('does not call Pixelforge about a finished generation', function () {
    Http::fake();
    $this->generation->update(['status' => GenerationStatus::Succeeded]);

    runPollGeneration($this->generation);

    Http::assertNothingSent();
});

test('waits when Pixelforge rate-limits the poll', function () {
    Http::fake([$this->url => Http::response(Pixelforge::error('rate_limit_error', 'rate_limit_exceeded'), 429, ['Retry-After' => '7'])]);

    runPollGeneration($this->generation)->assertReleased(7);
});

test('tries again later when Pixelforge does not answer', function () {
    Http::fake([$this->url => Http::sequence()->pushFailedConnection('cURL error 28: Operation timed out')]);

    runPollGeneration($this->generation);

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Queued);
    Queue::assertPushed(PollGeneration::class, fn (PollGeneration $job) => $job->poll === 2);
});

test('a poll and a webhook reporting the same success charge once', function () {
    Http::fake([$this->url => Http::response(Pixelforge::load('generation-succeeded'))]);
    runPollGeneration($this->generation);

    // The webhook for the same success arrives after the poll already recorded it.
    $body = (string) json_encode([
        'id' => 'evt_race_1',
        'object' => 'event',
        'type' => 'generation.succeeded',
        'created_at' => '2026-11-16T23:23:18Z',
        'data' => ['object' => Pixelforge::load('generation-succeeded')],
    ]);
    $timestamp = now()->getTimestamp();
    $this->call('POST', '/webhooks/pixelforge', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", 'whsec_test_secret'),
    ], content: $body)->assertNoContent();

    expect($this->generation->fresh()->charge_nano)->toBe(21_562_500)
        ->and(WebhookEvent::count())->toBe(1);
});

test('a successful submit schedules the first poll 20 seconds later', function () {
    $generation = Generation::factory()->create();
    Http::fake(['pixelforge.test/v1/generations' => Http::response(Pixelforge::load('generation-queued'), 202)]);

    $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    Queue::assertPushed(PollGeneration::class, fn (PollGeneration $job) => $job->generation->is($generation)
        && $job->poll === 1
        && $job->delay === 20);
});

test('shares the Pixelforge rate limit with every other Pixelforge call', function () {
    expect((new PollGeneration($this->generation))->middleware())
        ->toHaveCount(1)
        ->{0}->toBeInstanceOf(RateLimited::class);
});
