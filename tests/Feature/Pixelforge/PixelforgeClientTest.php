<?php

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Pixelforge\Exceptions\ConcurrencyLimited;
use App\Pixelforge\Exceptions\ContentPolicyViolation;
use App\Pixelforge\Exceptions\DailyLimitReached;
use App\Pixelforge\Exceptions\DateRangeTooLong;
use App\Pixelforge\Exceptions\IdempotencyKeyReused;
use App\Pixelforge\Exceptions\InvalidApiKey;
use App\Pixelforge\Exceptions\NotFound;
use App\Pixelforge\Exceptions\OutcomeUnknown;
use App\Pixelforge\Exceptions\Overloaded;
use App\Pixelforge\Exceptions\PixelforgeException;
use App\Pixelforge\Exceptions\RateLimited;
use App\Pixelforge\Exceptions\Rejected;
use App\Pixelforge\Exceptions\RetryLater;
use App\Pixelforge\Exceptions\ValidationFailed;
use App\Pixelforge\NewGeneration;
use App\Pixelforge\PixelforgeClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Pixelforge;

beforeEach(function () {
    $this->client = app(PixelforgeClient::class);
    $this->generation = new NewGeneration(
        prompt: 'A lighthouse at dusk',
        size: ImageSize::Landscape,
        quality: ImageQuality::Medium,
        referenceImages: ['https://example.com/reference-1.png'],
        metadata: ['local_id' => '42'],
    );
});

test('creates a generation with our key and the idempotency key', function () {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(Pixelforge::load('generation-queued'), 202)]);

    $snapshot = $this->client->createGeneration($this->generation, 'idem-123');

    expect($snapshot->id)->toBe('gen_01M7KDZJNGSRKY7HM2E2PMEGVA')
        ->and($snapshot->status)->toBe(GenerationStatus::Queued);

    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://pixelforge.test/v1/generations'
        && $request->hasHeader('Authorization', 'Bearer pf_test_key')
        && $request->hasHeader('Idempotency-Key', 'idem-123')
        && $request->data() === [
            'prompt' => 'A lighthouse at dusk',
            'size' => '1536x1024',
            'quality' => 'medium',
            'reference_images' => ['https://example.com/reference-1.png'],
            'metadata' => ['local_id' => '42'],
        ]);
});

test('leaves reference_images out when there are none', function () {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(Pixelforge::load('generation-queued'), 202)]);

    $this->client->createGeneration(new NewGeneration('A red fox', ImageSize::Square, ImageQuality::Low, [], ['local_id' => '7']), 'idem-7');

    Http::assertSent(fn (Request $request) => ! array_key_exists('reference_images', $request->data()));
});

test('sends a byte-identical request on every retry, so Pixelforge never sees different parameters', function () {
    Http::fake(['pixelforge.test/v1/generations' => Http::response(Pixelforge::load('generation-queued'), 202)]);

    $this->client->createGeneration($this->generation, 'idem-123');
    $this->client->createGeneration($this->generation, 'idem-123');

    [$first, $second] = Http::recorded()->map(fn (array $pair) => $pair[0]->body())->all();

    expect($first)->toBe($second);
});

test('fetches a generation', function () {
    Http::fake(['pixelforge.test/v1/generations/gen_01M7KAQH2VCJ8PYCW8AV4D9PHV' => Http::response(Pixelforge::load('generation-succeeded'))]);

    $snapshot = $this->client->getGeneration('gen_01M7KAQH2VCJ8PYCW8AV4D9PHV');

    expect($snapshot->status)->toBe(GenerationStatus::Succeeded)
        ->and($snapshot->usage?->reasoning)->toBe(480);

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->hasHeader('Authorization', 'Bearer pf_test_key'));
});

test('fetches the price sheet', function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(Pixelforge::load('pricing'))]);

    expect($this->client->pricing()->rates->reasoning)->toBe(12_500);
});

test('turns each Pixelforge error into its own exception, with its error code', function (int $status, array $body, string $exception, string $code) {
    Http::fake(['pixelforge.test/*' => Http::response($body, $status)]);

    try {
        $this->client->createGeneration($this->generation, 'idem-123');
        $this->fail('No exception was thrown.');
    } catch (PixelforgeException $e) {
        expect($e)->toBeInstanceOf($exception)
            ->and($e->errorCode)->toBe($code);
    }
})->with([
    'invalid key' => [401, Pixelforge::load('error-invalid-api-key'), InvalidApiKey::class, 'invalid_api_key'],
    'validation' => [422, Pixelforge::load('error-validation'), ValidationFailed::class, 'validation_failed'],
    'content policy' => [400, Pixelforge::load('error-content-policy'), ContentPolicyViolation::class, 'content_policy_violation'],
    'not found' => [404, Pixelforge::load('error-not-found'), NotFound::class, 'not_found'],
    'idempotency key reused' => [409, Pixelforge::load('error-idempotency-key-reused'), IdempotencyKeyReused::class, 'idempotency_key_reused'],
    'daily limit' => [429, Pixelforge::error('rate_limit_error', 'daily_limit_exceeded'), DailyLimitReached::class, 'daily_limit_exceeded'],
    'rate limit' => [429, Pixelforge::error('rate_limit_error', 'rate_limit_exceeded'), RateLimited::class, 'rate_limit_exceeded'],
    'concurrency limit' => [429, Pixelforge::error('rate_limit_error', 'concurrency_limit_exceeded'), ConcurrencyLimited::class, 'concurrency_limit_exceeded'],
    'overloaded' => [503, Pixelforge::load('error-overloaded'), Overloaded::class, 'overloaded'],
]);

test('groups errors by what to do next', function (int $status, array $body, string $family) {
    Http::fake(['pixelforge.test/*' => Http::response($body, $status)]);

    expect(fn () => $this->client->createGeneration($this->generation, 'idem-123'))->toThrow($family);
})->with([
    'content policy: rejected, nothing created' => [400, Pixelforge::load('error-content-policy'), Rejected::class],
    'daily limit: rejected, nothing created' => [429, Pixelforge::error('rate_limit_error', 'daily_limit_exceeded'), Rejected::class],
    'an error code we do not know: rejected' => [422, Pixelforge::error('invalid_request_error', 'date_range_too_long'), Rejected::class],
    'a 429 with a code we do not know: retry later' => [429, Pixelforge::error('rate_limit_error', 'some_new_limit'), RetryLater::class],
    'rate limit: retry later' => [429, Pixelforge::error('rate_limit_error', 'rate_limit_exceeded'), RetryLater::class],
    'concurrency limit: retry later' => [429, Pixelforge::error('rate_limit_error', 'concurrency_limit_exceeded'), RetryLater::class],
    'overloaded: retry later' => [503, Pixelforge::load('error-overloaded'), RetryLater::class],
]);

test('says how long to wait, using Retry-After or the documented default', function (int $status, string $code, array $headers, int $seconds) {
    Http::fake(['pixelforge.test/*' => Http::response(Pixelforge::error('rate_limit_error', $code), $status, $headers)]);

    try {
        $this->client->createGeneration($this->generation, 'idem-123');
        $this->fail('No exception was thrown.');
    } catch (RetryLater $e) {
        expect($e->retryAfter)->toBe($seconds);
    }
})->with([
    'rate limit, header' => [429, 'rate_limit_exceeded', ['Retry-After' => '17'], 17],
    'rate limit, no header' => [429, 'rate_limit_exceeded', [], 60],
    'concurrency, header' => [429, 'concurrency_limit_exceeded', ['Retry-After' => '5'], 5],
    'concurrency, no header' => [429, 'concurrency_limit_exceeded', [], 5],
    'overloaded, header' => [503, 'overloaded', ['Retry-After' => '2'], 2],
    'overloaded, no header' => [503, 'overloaded', [], 2],
    'overloaded, unreadable header' => [503, 'overloaded', ['Retry-After' => 'soon'], 2],
]);

test('keeps every field problem from a validation error', function () {
    Http::fake(['pixelforge.test/*' => Http::response(Pixelforge::load('error-validation'), 422)]);

    try {
        $this->client->createGeneration($this->generation, 'idem-123');
        $this->fail('No exception was thrown.');
    } catch (ValidationFailed $e) {
        expect($e->errors)->toHaveKeys(['prompt', 'size', 'colour'])
            ->and($e->errors['colour'])->toBe(['Unknown parameter: colour.']);
    }
});

test('reports an unknown outcome when it cannot tell whether Pixelforge created the generation', function (Closure $response) {
    Http::fake(['pixelforge.test/*' => $response]);

    expect(fn () => $this->client->createGeneration($this->generation, 'idem-123'))->toThrow(OutcomeUnknown::class);
})->with([
    'timeout' => [fn () => fn () => throw new ConnectionException('cURL error 28: Operation timed out')],
    'server error' => [fn () => Http::response(Pixelforge::error('api_error', 'internal_error'), 500)],
    'gateway page' => [fn () => Http::response('<html>Bad gateway</html>', 502)],
    'success without a generation' => [fn () => Http::response(['hello' => 'world'], 202)],
    'request timeout (408)' => [fn () => Http::response('', 408)],
]);

test('fetches usage for a date range', function () {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(Pixelforge::load('usage'))]);

    $report = $this->client->usage(CarbonImmutable::parse('2026-11-16'), CarbonImmutable::parse('2026-11-18'));

    expect($report->day('2026-11-16')->costNano)->toBe(17_250_000);
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://pixelforge.test/v1/usage?start_date=2026-11-16&end_date=2026-11-18'
        && $request->hasHeader('Authorization', 'Bearer pf_test_key'));
});

test('turns a range that is too long into DateRangeTooLong', function () {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(Pixelforge::error('invalid_request_error', 'date_range_too_long'), 422)]);

    expect(fn () => $this->client->usage(CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-11-18')))
        ->toThrow(DateRangeTooLong::class);
});

test("turns a usage answer it can't read into OutcomeUnknown", function () {
    Http::fake(['pixelforge.test/v1/usage*' => Http::response(['object' => 'usage'])]);

    expect(fn () => $this->client->usage(CarbonImmutable::parse('2026-11-16'), CarbonImmutable::parse('2026-11-18')))
        ->toThrow(OutcomeUnknown::class);
});

test('never puts the API key or the response body in an error message', function () {
    Http::fake(['pixelforge.test/*' => Http::response('Echo: Authorization: Bearer pf_test_key', 500)]);

    try {
        $this->client->createGeneration($this->generation, 'idem-123');
        $this->fail('No exception was thrown.');
    } catch (OutcomeUnknown $e) {
        expect($e->getMessage())->not->toContain('pf_test_key');
    }
});
