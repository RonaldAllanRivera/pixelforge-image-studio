<?php

use App\Enums\GenerationStatus;
use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Pixelforge;

beforeEach(function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(Pixelforge::load('pricing'))]);
    Queue::fake();
    $this->user = User::factory()->create();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function generationForm(array $overrides = []): array
{
    return [...['prompt' => 'A red fox', 'size' => '1024x1024', 'quality' => 'medium', 'reference_images' => []], ...$overrides];
}

test('submits a generation and sends it to Pixelforge in the background', function () {
    $this->actingAs($this->user)->post(route('generations.store'), generationForm())
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('studio'));

    expect(Generation::sole())
        ->user_id->toBe($this->user->id)
        ->status->toBe(GenerationStatus::Pending);
    Queue::assertPushed(SubmitGeneration::class);
});

test('accepts 4,000 emoji and refuses 4,001, counting characters as Pixelforge does', function () {
    $this->actingAs($this->user)->post(route('generations.store'), generationForm(['prompt' => str_repeat('🦊', 4000)]))
        ->assertSessionHasNoErrors();
    $this->actingAs($this->user)->post(route('generations.store'), generationForm(['prompt' => str_repeat('🦊', 4001)]))
        ->assertSessionHasErrors('prompt');

    expect(Generation::count())->toBe(1);
});

test('refuses what Pixelforge would refuse', function (array $field, string $error) {
    $this->actingAs($this->user)->post(route('generations.store'), generationForm($field))
        ->assertSessionHasErrors($error);

    expect(Generation::count())->toBe(0);
})->with([
    'no prompt' => [['prompt' => ''], 'prompt'],
    'unknown size' => [['size' => '512x512'], 'size'],
    'unknown quality' => [['quality' => 'ultra'], 'quality'],
    'a fifth reference image' => [['reference_images' => array_fill(0, 5, 'https://example.com/a.png')], 'reference_images'],
    'an ftp link' => [['reference_images' => ['ftp://example.com/a.png']], 'reference_images.0'],
    'a javascript link' => [['reference_images' => ['javascript:alert(1)']], 'reference_images.0'],
]);

test('ignores blank reference image rows', function () {
    $this->actingAs($this->user)->post(route('generations.store'), generationForm([
        'reference_images' => ['', 'https://example.com/a.png', ''],
    ]))->assertSessionHasNoErrors();

    expect(Generation::sole()->reference_images)->toBe(['https://example.com/a.png']);
});
