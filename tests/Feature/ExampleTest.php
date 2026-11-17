<?php

use Inertia\Testing\AssertableInertia as Assert;

test('shows the landing page to visitors', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Welcome'));
});
