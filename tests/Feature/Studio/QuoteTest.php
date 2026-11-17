<?php

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Tests\Fixtures\Pixelforge;

beforeEach(function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(Pixelforge::load('pricing'))]);
    $this->user = User::factory()->create();
});

test('quotes every quality for a prompt, with a breakdown of the chosen one', function () {
    $response = $this->actingAs($this->user)->postJson(route('studio.quote'), [
        'prompt' => 'A red fox',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
    ]);

    $response->assertOk()
        ->assertJsonPath('characters', 9)
        ->assertJsonPath('qualities', [
            'low' => ['estimated' => '$0.0179', 'maximum' => '$0.0219'],
            'medium' => ['estimated' => '$0.0722', 'maximum' => '$0.0863'],
            'high' => ['estimated' => '$0.2853', 'maximum' => '$0.3401'],
        ])
        ->assertJsonPath('breakdown.estimated_charge', '$0.072206250')
        ->assertJsonPath('breakdown.maximum_fee', '$0.017255000')
        ->assertJsonPath('breakdown.rows.3', [
            'label' => 'Reasoning', 'estimated_tokens' => 1100, 'maximum_tokens' => 2000, 'rate' => '$12.50',
        ]);
});

test("matches the docs' worst case", function () {
    $response = $this->actingAs($this->user)->postJson(route('studio.quote'), [
        'prompt' => str_repeat('a', 400),
        'size' => '1536x1024',
        'quality' => 'high',
        'reference_images' => ['https://example.com/a.png', 'https://example.com/b.png'],
    ]);

    $response->assertOk()
        ->assertJsonPath('breakdown.maximum_cost', '$0.373875000')
        ->assertJsonPath('qualities.high.maximum', '$0.4674');
});

test('counts emoji as one character each, as Pixelforge does', function () {
    $this->actingAs($this->user)
        ->postJson(route('studio.quote'), ['prompt' => '🦊🦊🦊', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertOk()
        ->assertJsonPath('characters', 3);
});

test('quotes an empty prompt while the user is still typing', function () {
    $this->actingAs($this->user)
        ->postJson(route('studio.quote'), ['prompt' => '', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertOk()
        ->assertJsonPath('characters', 0);
});

test('refuses a fifth reference image', function () {
    $this->actingAs($this->user)
        ->postJson(route('studio.quote'), [
            'prompt' => 'A red fox',
            'size' => '1024x1024',
            'quality' => 'low',
            'reference_images' => array_fill(0, 5, 'https://example.com/a.png'),
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('reference_images');
});

test('is for signed-in users only', function () {
    $this->postJson(route('studio.quote'), ['prompt' => 'A red fox', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertUnauthorized();
});
