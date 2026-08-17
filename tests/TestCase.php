<?php

namespace LiveNetworks\LnStarter\Tests;

use LiveNetworks\LnStarter\LnStarterServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LnStarterServiceProvider::class,
        ];
    }
}
