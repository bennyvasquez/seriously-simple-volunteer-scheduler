<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Request;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // The trusted host list lives on the Request class, outside the app, so a test that sends a request looking like production (ExpiredFormTest) would otherwise leave its list behind for every test after it.
        Request::setTrustedHosts([]);
    }
}
