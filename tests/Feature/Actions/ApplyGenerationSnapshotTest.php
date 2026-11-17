<?php

use App\Actions\ApplyGenerationSnapshot;
use App\Enums\GenerationStatus;
use App\Models\Generation;
use Illuminate\Support\Facades\Log;
use Tests\Fixtures\Pixelforge;

beforeEach(function () {
    $this->apply = app(ApplyGenerationSnapshot::class);
    $this->generation = Generation::factory()->create(); // pending; the user was quoted at most $0.086275
});

test('links the generation to Pixelforge and moves it forward', function () {
    $this->apply->handle($this->generation, Pixelforge::snapshot('generation-queued'));
    $generation = $this->generation->fresh();

    expect($generation->status)->toBe(GenerationStatus::Queued)
        ->and($generation->provider_id)->toBe('gen_01M7KDZJNGSRKY7HM2E2PMEGVA')
        ->and($generation->rates)->toBe(Pixelforge::load('generation-queued')['rates']);

    $this->apply->handle($generation, Pixelforge::snapshot('generation-queued', ['status' => 'processing', 'started_at' => '2026-11-17T00:19:50Z']));
    $generation = $generation->fresh();

    expect($generation->status)->toBe(GenerationStatus::Processing)
        ->and($generation->started_at?->toIso8601ZuluString())->toBe('2026-11-17T00:19:50Z');
});

test('records the exact cost and charge when it succeeds', function () {
    $this->apply->handle($this->generation, Pixelforge::snapshot('generation-succeeded'));
    $generation = $this->generation->fresh();

    expect($generation->status)->toBe(GenerationStatus::Succeeded)
        ->and([$generation->input_text_tokens, $generation->input_image_tokens, $generation->output_tokens, $generation->reasoning_tokens])
        ->toBe([10, 0, 280, 480])
        ->and($generation->provider_cost_nano)->toBe(17_250_000)
        ->and($generation->charge_nano)->toBe(21_562_500)
        ->and($generation->image_url)->toStartWith('https://pixelforge.promptedit.dev/images/gen_01M7KAQH2VCJ8PYCW8AV4D9PHV.png')
        ->and($generation->image_url_expires_at?->toIso8601ZuluString())->toBe('2026-11-17T23:23:17Z')
        ->and($generation->completed_at?->toIso8601ZuluString())->toBe('2026-11-16T23:23:17Z');
});

test('records a success once, even when another report of it arrives later', function () {
    $this->apply->handle($this->generation, Pixelforge::snapshot('generation-succeeded'));
    $this->apply->handle($this->generation, Pixelforge::snapshot('generation-succeeded', ['usage' => ['reasoning_tokens' => 8_000]]));
    $generation = $this->generation->fresh();

    expect($generation->reasoning_tokens)->toBe(480)
        ->and($generation->charge_nano)->toBe(21_562_500);
});

test('ignores a processing event that arrives after success', function () {
    $this->apply->handle($this->generation, Pixelforge::snapshot('generation-succeeded'));
    $this->apply->handle($this->generation, Pixelforge::snapshot('generation-succeeded', ['status' => 'processing']));

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Succeeded);
});

test('ignores a late create response after the generation started processing', function () {
    $this->apply->handle($this->generation, Pixelforge::snapshot('generation-queued', ['status' => 'processing']));
    $this->apply->handle($this->generation, Pixelforge::snapshot('generation-queued'));

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Processing);
});

test('never changes a final status', function () {
    $this->apply->handle($this->generation, Pixelforge::snapshot('generation-failed'));
    $this->apply->handle($this->generation, Pixelforge::snapshot('generation-succeeded', ['id' => 'gen_01M7KDZJNGSRKY7HM2E2PMEGVA']));
    $generation = $this->generation->fresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->charge_nano)->toBe(0);
});

test('a failed generation costs nothing and keeps the reason', function () {
    $this->apply->handle($this->generation, Pixelforge::snapshot('generation-failed'));
    $generation = $this->generation->fresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->provider_cost_nano)->toBe(0)
        ->and($generation->charge_nano)->toBe(0)
        ->and($generation->error_code)->toBe('generation_failed')
        ->and($generation->error_message)->toBe('The model could not produce an image for this prompt. You were not charged.');
});

test('bills at the rates fixed on the generation, not at today\'s prices', function () {
    // Pixelforge doubled the output rate before this generation was created: $80 per 1M output tokens.
    $this->apply->handle($this->generation, Pixelforge::snapshot('generation-succeeded', ['rates' => ['output_tokens' => '80.00']]));
    $generation = $this->generation->fresh();

    // 10 × $5 + 280 × $80 + 480 × $12.50 per 1M tokens.
    expect($generation->provider_cost_nano)->toBe(28_450_000)
        ->and($generation->charge_nano)->toBe(35_562_500);
});

test('never charges the user more than the maximum they were quoted', function () {
    Log::spy();
    $generation = Generation::factory()->create(['max_charge_nano' => 20_000_000]);

    $this->apply->handle($generation, Pixelforge::snapshot('generation-succeeded'));
    $generation = $generation->fresh();

    expect($generation->charge_nano)->toBe(20_000_000)          // capped: we keep the promise
        ->and($generation->provider_cost_nano)->toBe(17_250_000); // what Pixelforge bills us is still exact

    Log::shouldHaveReceived('warning')->once();
});

test('ignores news about a different Pixelforge generation', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Queued, 'provider_id' => 'gen_someone_else']);

    $this->apply->handle($generation, Pixelforge::snapshot('generation-succeeded'));

    expect($generation->fresh()->status)->toBe(GenerationStatus::Queued)
        ->and($generation->fresh()->charge_nano)->toBeNull();
});

test('refuses a success report without usage, and changes nothing', function () {
    expect(fn () => $this->apply->handle($this->generation, Pixelforge::snapshot('generation-succeeded', ['usage' => null])))
        ->toThrow(InvalidArgumentException::class);

    expect($this->generation->fresh()->status)->toBe(GenerationStatus::Pending);
});

test('fails a generation Pixelforge never accepted, at no cost', function () {
    $this->apply->failBeforeAccepted($this->generation, 'content_policy_violation', 'Your prompt was rejected by our content policy.');
    $generation = $this->generation->fresh();

    expect($generation->status)->toBe(GenerationStatus::Failed)
        ->and($generation->provider_cost_nano)->toBe(0)
        ->and($generation->charge_nano)->toBe(0)
        ->and($generation->error_code)->toBe('content_policy_violation');
});

test('does not fail a generation once Pixelforge has it', function () {
    $generation = Generation::factory()->create(['status' => GenerationStatus::Queued, 'provider_id' => 'gen_1']);

    $this->apply->failBeforeAccepted($generation, 'overloaded', 'Pixelforge is busy.');

    expect($generation->fresh()->status)->toBe(GenerationStatus::Queued);
});
