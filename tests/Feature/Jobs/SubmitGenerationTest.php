<?php

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Enums\WaitingReason;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Http\Client\Request;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\Fixtures\Pixelforge;

beforeEach(function () {
    $this->generation = Generation::factory()->create([
        'prompt' => 'A lighthouse at dusk',
        'size' => ImageSize::Landscape,
        'quality' => ImageQuality::Medium,
        'reference_images' => ['https://example.com/reference-1.png'],
    ]);
});

/**
 * Run one attempt of the job, the way a queue worker would.
 */
function runSubmitGeneration(Generation $generation): SubmitGeneration
{
    $job = (new SubmitGeneration($generation))->withFakeQueueInteractions();
    app()->call([$job, 'handle']);

    return $job;
}

function fakePixelforgeCreate(mixed $response): void
{
    Http::fake(['pixelforge.test/v1/generations' => $response]);
}

test('sends the saved request with the generation\'s own idempotency key', function () {
    fakePixelforgeCreate(Http::response(Pixelforge::load('generation-queued'), 202));

    $job = runSubmitGeneration($this->generation);

    Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', $this->generation->idempotency_key)
        && $request->data() === [
            'prompt' => 'A lighthouse at dusk',
            'size' => '1536x1024',
            'quality' => 'medium',
            'reference_images' => ['https://example.com/reference-1.png'],
            'metadata' => ['local_id' => (string) $this->generation->id, 'env' => 'testing'],
        ]);

    expect($this->generation->fresh())
        ->status->toBe(GenerationStatus::Queued)
        ->provider_id->toBe('gen_01M7KDZJNGSRKY7HM2E2PMEGVA')
        ->submit_attempts->toBe(1);
    $job->assertNotReleased();
});

test('does nothing once Pixelforge has the generation', function () {
    Http::fake();
    $this->generation->update(['status' => GenerationStatus::Queued]);

    runSubmitGeneration($this->generation)->assertNotReleased();

    Http::assertNothingSent();
});

test('waits for a free slot while three generations are already running', function () {
    Http::fake();
    Generation::factory()->count(2)->create(['status' => GenerationStatus::Queued]);
    Generation::factory()->create(['status' => GenerationStatus::Processing]);

    runSubmitGeneration($this->generation)->assertReleased(5);

    Http::assertNothingSent();
    expect($this->generation->fresh()->waiting_reason)->toBe(WaitingReason::FreeSlot);
});

test('waits when Pixelforge says the account is at its concurrency limit', function () {
    fakePixelforgeCreate(Http::response(Pixelforge::error('rate_limit_error', 'concurrency_limit_exceeded'), 429, ['Retry-After' => '5']));

    runSubmitGeneration($this->generation)->assertReleased(5);

    expect($this->generation->fresh())
        ->status->toBe(GenerationStatus::Pending)
        ->waiting_reason->toBe(WaitingReason::FreeSlot);
});

test('waits out a rate limit', function () {
    fakePixelforgeCreate(Http::response(Pixelforge::error('rate_limit_error', 'rate_limit_exceeded'), 429, ['Retry-After' => '17']));

    runSubmitGeneration($this->generation)->assertReleased(17);

    expect($this->generation->fresh()->waiting_reason)->toBe(WaitingReason::RateLimit);
});

test('retries an overloaded Pixelforge with growing waits', function (int $previousAttempts, int $wait) {
    fakePixelforgeCreate(Http::response(Pixelforge::load('error-overloaded'), 503, ['Retry-After' => '2']));
    $this->generation->forceFill(['submit_attempts' => $previousAttempts])->save();

    runSubmitGeneration($this->generation)->assertReleased($wait);

    expect($this->generation->fresh())
        ->status->toBe(GenerationStatus::Pending)
        ->waiting_reason->toBe(WaitingReason::Overloaded);
})->with([
    'first try' => [0, 2],
    'second try' => [1, 4],
    'third try' => [2, 8],
    'fourth try' => [3, 16],
]);

test('gives up on an overloaded Pixelforge after five tries, at no cost', function () {
    fakePixelforgeCreate(Http::response(Pixelforge::load('error-overloaded'), 503, ['Retry-After' => '2']));
    $this->generation->forceFill(['submit_attempts' => 4])->save();

    runSubmitGeneration($this->generation)->assertNotReleased();

    expect($this->generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe('overloaded')
        ->charge_nano->toBe(0);
});

test('fails at no cost when Pixelforge rejects the request', function (int $status, array $body, string $code) {
    fakePixelforgeCreate(Http::response($body, $status));

    runSubmitGeneration($this->generation)->assertNotReleased();

    expect($this->generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->error_code->toBe($code)
        ->provider_cost_nano->toBe(0)
        ->charge_nano->toBe(0);
})->with([
    'content policy' => [400, Pixelforge::load('error-content-policy'), 'content_policy_violation'],
    'daily limit' => [429, Pixelforge::error('rate_limit_error', 'daily_limit_exceeded'), 'daily_limit_exceeded'],
]);

test('reports our own mistakes loudly', function (int $status, array $body) {
    Log::spy();
    fakePixelforgeCreate(Http::response($body, $status));

    runSubmitGeneration($this->generation);

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Failed);
    Log::shouldHaveReceived('error')->once();
})->with([
    'validation failed' => [422, Pixelforge::load('error-validation')],
    'invalid API key' => [401, Pixelforge::load('error-invalid-api-key')],
    'idempotency key reused' => [409, Pixelforge::load('error-idempotency-key-reused')],
]);

test('keeps a generation pending after a timeout and retries with the same key', function () {
    fakePixelforgeCreate(Http::sequence()
        ->pushFailedConnection('cURL error 28: Operation timed out')
        ->push(Pixelforge::load('generation-queued'), 202, ['Idempotent-Replayed' => 'true']));

    runSubmitGeneration($this->generation)->assertReleased(10);
    expect($this->generation->fresh())
        ->status->toBe(GenerationStatus::Pending)
        ->waiting_reason->toBe(WaitingReason::UnknownOutcome);

    runSubmitGeneration($this->generation->fresh())->assertNotReleased();
    expect($this->generation->fresh())
        ->status->toBe(GenerationStatus::Queued)
        ->submit_attempts->toBe(2);

    $keys = Http::recorded()->map(fn (array $pair) => $pair[0]->header('Idempotency-Key')[0])->unique();
    expect($keys->all())->toBe([$this->generation->idempotency_key]);
});

test('shares the Pixelforge rate limit with every other Pixelforge call', function () {
    expect((new SubmitGeneration($this->generation))->middleware())
        ->toHaveCount(1)
        ->{0}->toBeInstanceOf(RateLimited::class);
});

test('keeps trying for 30 minutes before leaving it to the scheduled check', function () {
    $this->freezeTime();

    expect((new SubmitGeneration($this->generation))->retryUntil()->equalTo(now()->addMinutes(30)))->toBeTrue();
});
