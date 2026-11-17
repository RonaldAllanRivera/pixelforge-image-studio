<?php

use App\Enums\GenerationStatus;
use App\Enums\ImageQuality;
use App\Enums\ImageSize;
use App\Models\Generation;
use App\Models\WebhookEvent;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\Fixtures\Pixelforge;
use Tests\TestCase;

/**
 * Post a webhook the way Pixelforge does: the raw JSON body, signed with the test secret.
 *
 * @param  array<string, mixed>  $event
 */
function postPixelforgeWebhook(TestCase $test, array $event, ?string $signature = null, ?int $timestamp = null): TestResponse
{
    $body = (string) json_encode($event, JSON_UNESCAPED_SLASHES);
    $timestamp ??= now()->getTimestamp();
    $signature ??= 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$body, 'whsec_test_secret');

    return $test->call('POST', '/webhooks/pixelforge', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_PIXELFORGE_SIGNATURE' => $signature,
        'HTTP_PIXELFORGE_EVENT_ID' => (string) ($event['id'] ?? ''),
        'HTTP_PIXELFORGE_DELIVERY_ATTEMPT' => '1',
    ], content: $body);
}

beforeEach(function () {
    // The generation behind the captured webhook fixtures: medium, 1536x1024, one reference image.
    $this->generation = Generation::factory()->create([
        'status' => GenerationStatus::Processing,
        'provider_id' => 'gen_01M7KE16V4095Y7FRGB0NW8ZC4',
        'prompt' => 'Fixture capture: a lighthouse at dusk [succeed]',
        'size' => ImageSize::Landscape,
        'quality' => ImageQuality::Medium,
        'reference_images' => ['https://example.com/reference-1.png'],
        'max_cost_nano' => 98_720_000,
        'max_charge_nano' => 123_400_000,
    ]);
});

test('applies a signed succeeded event and charges exactly', function () {
    postPixelforgeWebhook($this, Pixelforge::load('event-generation-succeeded'))->assertNoContent();

    // 12 × $5 + 765 × $10 + 1,650 × $40 + 976 × $12.50 per 1M tokens.
    expect($this->generation->fresh())
        ->status->toBe(GenerationStatus::Succeeded)
        ->provider_cost_nano->toBe(85_910_000)
        ->charge_nano->toBe(107_387_500);

    expect(WebhookEvent::sole())
        ->event_id->toBe('evt_01M7KE1MQM0SZ24C3WGMHR3PG8')
        ->generation_id->toBe($this->generation->id)
        ->processed_at->not->toBeNull();
});

test('processes a duplicate delivery once', function () {
    $event = Pixelforge::load('event-generation-succeeded');

    postPixelforgeWebhook($this, $event)->assertNoContent();
    postPixelforgeWebhook($this, $event)->assertNoContent();

    expect(WebhookEvent::count())->toBe(1)
        ->and($this->generation->fresh()->charge_nano)->toBe(107_387_500);
});

test('ends in the right state when events arrive out of order', function () {
    postPixelforgeWebhook($this, Pixelforge::load('event-generation-succeeded'))->assertNoContent();
    postPixelforgeWebhook($this, Pixelforge::load('event-generation-processing'))->assertNoContent();

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Succeeded)
        ->and(WebhookEvent::count())->toBe(2);
});

test('finds the generation by our own id when the webhook beats the create response', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Pending]);
    $event = Pixelforge::load('event-generation-failed');
    $event['data']['object']['metadata'] = ['local_id' => $generation->idempotency_key, 'env' => 'testing'];

    postPixelforgeWebhook($this, $event)->assertNoContent();

    expect($generation->fresh())
        ->status->toBe(GenerationStatus::Failed)
        ->provider_id->toBe('gen_01M7KDZJNGSRKY7HM2E2PMEGVA')
        ->charge_nano->toBe(0);
});

test('ignores our id when the event comes from another environment', function () {
    Log::spy();
    $generation = Generation::factory()->create(['status' => GenerationStatus::Pending]);
    $event = Pixelforge::load('event-generation-failed');
    $event['data']['object']['metadata'] = ['local_id' => $generation->idempotency_key, 'env' => 'production'];

    postPixelforgeWebhook($this, $event)->assertNoContent();

    expect($generation->fresh()->status)->toBe(GenerationStatus::Pending);
    Log::shouldHaveReceived('warning')->once();
});

test('rejects a bad signature, a changed body and a stale timestamp, storing nothing', function (Closure $send) {
    $send($this)->assertStatus(400);

    expect(WebhookEvent::count())->toBe(0)
        ->and($this->generation->fresh()->status)->toBe(GenerationStatus::Processing);
})->with([
    'wrong signature' => [fn (TestCase $test) => postPixelforgeWebhook($test, Pixelforge::load('event-generation-succeeded'), signature: 't='.now()->getTimestamp().',v1=deadbeef')],
    'no signature' => [fn (TestCase $test) => postPixelforgeWebhook($test, Pixelforge::load('event-generation-succeeded'), signature: '')],
    'stale timestamp' => [fn (TestCase $test) => postPixelforgeWebhook($test, Pixelforge::load('event-generation-succeeded'), timestamp: now()->getTimestamp() - 301)],
    'body changed after signing' => [function (TestCase $test) {
        $original = (string) json_encode(Pixelforge::load('event-generation-succeeded'), JSON_UNESCAPED_SLASHES);
        $timestamp = now()->getTimestamp();
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$original, 'whsec_test_secret');

        return $test->call('POST', '/webhooks/pixelforge', server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_PIXELFORGE_SIGNATURE' => $signature,
        ], content: str_replace('"output_tokens":1650', '"output_tokens":1', $original));
    }],
]);

test('runs the webhook route without sessions, cookies or CSRF, because Pixelforge signs requests instead', function () {
    // Laravel skips CSRF checks while testing, so check the middleware the route really runs.
    // Creating the HTTP kernel registers the "web" group with the router, as a real request does.
    app(HttpKernel::class);
    $router = app('router');
    $route = $router->getRoutes()->getByName('webhooks.pixelforge');

    expect($router->gatherRouteMiddleware($route))
        ->not->toContain(StartSession::class)
        ->not->toContain(EncryptCookies::class)
        ->not->toContain(PreventRequestForgery::class)
        ->and($route->gatherMiddleware())->not->toContain('web');
});

test('answers a ping', function () {
    postPixelforgeWebhook($this, ['id' => 'evt_ping_1', 'object' => 'event', 'type' => 'ping', 'created_at' => '2026-11-17T00:00:00Z', 'data' => []])
        ->assertNoContent();

    expect(WebhookEvent::sole()->type)->toBe('ping');
});

test('stores an event for a generation we do not know, and answers 204', function () {
    Log::spy();
    $event = Pixelforge::load('event-generation-succeeded');
    $event['data']['object']['id'] = 'gen_not_ours';

    postPixelforgeWebhook($this, $event)->assertNoContent();

    expect(WebhookEvent::count())->toBe(1);
    Log::shouldHaveReceived('warning')->once();
});

test('a failure while applying stores nothing, so Pixelforge\'s retry is processed', function () {
    $broken = Pixelforge::load('event-generation-succeeded');
    $broken['data']['object']['usage'] = null; // a success without usage cannot be billed

    postPixelforgeWebhook($this, $broken)->assertStatus(500);
    expect(WebhookEvent::count())->toBe(0);

    postPixelforgeWebhook($this, Pixelforge::load('event-generation-succeeded'))->assertNoContent();
    expect($this->generation->fresh()->charge_nano)->toBe(107_387_500);
});
