<?php

declare(strict_types=1);
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test suite configuration
|--------------------------------------------------------------------------
|
| Closure-style Pest tests under tests/ bind the Laravel TestCase and
| RefreshDatabase. Module-owned tests follow their own conventions
| (see CONTRIBUTING.md):
|   - app/Modules/<M>/Tests/Unit    -> Pest closures, no Laravel bindings
|   - app/Modules/<M>/Tests/Feature -> PHPUnit classes extending Tests\TestCase
*/

uses(
    TestCase::class,
    RefreshDatabase::class,
)->in('Feature');
