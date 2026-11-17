<?php

use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Fixtures\Pixelforge;

beforeEach(function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(Pixelforge::load('pricing'))]);
});

test('guests are sent to the login page', function () {
    $this->get(route('studio'))->assertRedirect(route('login'));
});

test('login lands on the studio', function () {
    expect(config('fortify.home'))->toBe('/studio');
});

test("lists only the user's own generations, newest first, 12 per page", function () {
    $user = User::factory()->create();
    $mine = Generation::factory()->count(13)->for($user)->create();
    Generation::factory()->count(2)->create();

    $this->actingAs($user)->get(route('studio'))->assertInertia(fn (Assert $page) => $page
        ->component('Studio')
        ->has('generations.data', 12)
        ->where('generations.data.0.id', $mine->last()->id)
        ->where('generations.meta.total', 13)
    );
});

test('gives the form its choices and limits', function () {
    $this->actingAs(User::factory()->create())->get(route('studio'))->assertInertia(fn (Assert $page) => $page
        ->where('sizes', [
            ['value' => '1024x1024', 'label' => 'Square'],
            ['value' => '1536x1024', 'label' => 'Landscape'],
            ['value' => '1024x1536', 'label' => 'Portrait'],
        ])
        ->where('qualities', [
            ['value' => 'low', 'label' => 'Low'],
            ['value' => 'medium', 'label' => 'Medium'],
            ['value' => 'high', 'label' => 'High'],
        ])
        ->where('limits', ['prompt' => 4000, 'referenceImages' => 4, 'tokensPerReferenceImage' => 765])
    );
});
