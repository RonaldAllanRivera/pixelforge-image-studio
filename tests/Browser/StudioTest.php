<?php

use App\Jobs\SubmitGeneration;
use App\Models\Generation;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Fixtures\Pixelforge;

beforeEach(function () {
    Http::fake(['pixelforge.test/v1/pricing' => Http::response(Pixelforge::load('pricing'))]);
    Queue::fake();
});

test('a signed-in user prices and generates an image', function () {
    $this->actingAs(User::factory()->withCredits()->create());

    visit(route('studio'))
        ->type('@prompt', 'A red fox [succeed]')
        ->waitForText('Estimated')
        ->click('@generate')
        ->waitForText('A red fox [succeed]')
        ->assertSee('Your images')
        ->assertNoJavascriptErrors();

    expect(Generation::count())->toBe(1);
    Queue::assertPushed(SubmitGeneration::class);
});

test('the Studio fits a phone screen', function () {
    $this->actingAs(User::factory()->withCredits()->create());

    visit(route('studio'))->on()->mobile()
        ->type('@prompt', 'A red fox')
        ->waitForText('Estimated')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->assertNoJavascriptErrors();
});
