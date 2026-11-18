<?php

use App\Credits\CreditLedger;
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
            'low' => ['estimated' => '$0.0179', 'maximum' => '$0.0219', 'affordable' => false],
            'medium' => ['estimated' => '$0.0722', 'maximum' => '$0.0863', 'affordable' => false],
            'high' => ['estimated' => '$0.2853', 'maximum' => '$0.3401', 'affordable' => false],
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

test('marks each quality affordable or not, compared on the server', function () {
    $user = User::factory()->create();
    (new CreditLedger)->purchase($user, 50_000_000);

    // $user still says $0 in memory: the quote reads the balance as stored now.
    $this->actingAs($user)->postJson(route('studio.quote'), [
        'prompt' => 'A red fox',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
    ])
        ->assertOk()
        // The balance on screen comes from the shared page props, so the quote doesn't repeat it.
        ->assertJsonMissingPath('balance')
        ->assertJsonPath('qualities.low.affordable', true)     // at most 21,837,500
        ->assertJsonPath('qualities.medium.affordable', false) // at most 86,275,000
        ->assertJsonPath('qualities.high.affordable', false);  // at most 340,025,000
});

test('a balance exactly equal to the maximum is enough', function () {
    $user = User::factory()->create();
    (new CreditLedger)->purchase($user, 86_275_000); // medium's maximum for this prompt, to the nanodollar

    $this->actingAs($user)->postJson(route('studio.quote'), [
        'prompt' => 'A red fox',
        'size' => '1024x1024',
        'quality' => 'medium',
        'reference_images' => [],
    ])
        ->assertOk()
        ->assertJsonPath('qualities.medium.affordable', true)
        ->assertJsonPath('qualities.high.affordable', false);
});

test('is for signed-in users only', function () {
    $this->postJson(route('studio.quote'), ['prompt' => 'A red fox', 'size' => '1024x1024', 'quality' => 'low'])
        ->assertUnauthorized();
});
