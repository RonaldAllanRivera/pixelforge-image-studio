<?php

use App\Enums\GenerationStatus;
use App\Jobs\PollGeneration;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Queue::fake();
});

test('asks Pixelforge about accepted generations with no news for 2 minutes', function () {
    $quiet = Generation::factory()->create(['status' => GenerationStatus::Processing, 'provider_id' => 'gen_quiet']);
    $this->travel(3)->minutes();
    Generation::factory()->create(['status' => GenerationStatus::Processing, 'provider_id' => 'gen_recent']);

    $this->artisan('generations:reconcile')->assertSuccessful();

    Queue::assertPushed(PollGeneration::class, 1);
    Queue::assertPushed(PollGeneration::class, fn (PollGeneration $job) => $job->generation->is($quiet));
});

test('submits generations Pixelforge never accepted again, with the same key', function () {
    $stuck = Generation::factory()->create(['status' => GenerationStatus::Pending]);
    $this->travel(3)->minutes();
    Generation::factory()->create(['status' => GenerationStatus::Pending]);

    $this->artisan('generations:reconcile')->assertSuccessful();

    Queue::assertPushed(SubmitGeneration::class, 1);
    Queue::assertPushed(SubmitGeneration::class, fn (SubmitGeneration $job) => $job->generation->is($stuck));
});

test('leaves finished generations alone', function () {
    Generation::factory()->create(['status' => GenerationStatus::Succeeded, 'provider_id' => 'gen_done']);
    Generation::factory()->create(['status' => GenerationStatus::Failed]);
    $this->travel(3)->minutes();

    $this->artisan('generations:reconcile')->assertSuccessful();

    Queue::assertNothingPushed();
});

test('runs every hour', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn (Event $event) => str_contains((string) $event->command, 'generations:reconcile'));

    expect($event)->not->toBeNull()
        ->and($event?->expression)->toBe('0 * * * *');
});
