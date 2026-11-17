<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
| Feature tests boot Laravel and run against the Postgres test database,
| refreshed for every test. Unit tests are plain PHP: no framework, no database.
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
