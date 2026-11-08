<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Cache;

abstract class TestCase extends BaseTestCase {
    protected function setUp(): void {
        parent::setUp();

        // The `array` cache store persists for the whole process, so rate
        // limiter counters (login, register) would otherwise leak between
        // tests. Start each test with an empty cache.
        Cache::flush();
    }
}
