<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Sleep;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The trusted host list lives on the Request class, outside the app, so a test that sends a request looking like production (ExpiredFormTest) would otherwise leave its list behind for every test after it.
        Request::setTrustedHosts([]);

        // A failed password login and a password reset request are padded to a fixed time (config auth.timebox_duration) with Laravel's Sleep, which would otherwise really wait in every test that makes one. Tests that check the padding look at the Timebox, not the clock.
        Sleep::fake();
    }
}
