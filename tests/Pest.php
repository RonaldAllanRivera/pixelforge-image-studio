<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Feature tests boot Laravel and run against the Postgres test database,
| refreshed for every test. Unit tests are plain PHP: no framework, no database.
| Browser tests drive real Chromium (Playwright) against the app, served in-process
| with the built assets, so they need `npm run build` first.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(function () {
        // The base TestCase renders pages without assets; a real browser needs them.
        $this->withVite();
    })
    ->in('Browser');
