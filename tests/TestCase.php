<?php

namespace Tests;

use App\Services\Sms\SmsSender;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\FakeSmsSender;

abstract class TestCase extends BaseTestCase
{
    /**
     * Swap the SMS gateway for a recording fake and return it.
     */
    protected function fakeSms(): FakeSmsSender
    {
        $fake = new FakeSmsSender;

        $this->app->instance(SmsSender::class, $fake);

        return $fake;
    }
}
