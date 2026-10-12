<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Middleware\ThrottleRequests;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        /*
         * Rate limiter keys are md5-hashed by default, which makes them unreadable
         * and therefore untestable: no test could clear the counter it just filled,
         * so counts would leak from one test into the next and produce failures
         * that depend on execution order rather than on anything real.
         *
         * Turning hashing off exposes the key each limiter declares - the limiter
         * name and the key from AppServiceProvider, both of which are meant to be
         * reviewable. This is a test-environment concern only; production still
         * hashes, and the key is not a secret either way.
         */
        ThrottleRequests::shouldHashKeys(false);
    }
}
