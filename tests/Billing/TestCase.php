<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing;

use Dmitryisaenko\LaraFoundry\LaraFoundryServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Base case for the billing module suites (until v0.45.0 the separate add-on's own
 * test suite). Boots the core provider, which registers the billing module itself;
 * each derived case sets the billing config it needs before boot.
 */
abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaraFoundryServiceProvider::class,
        ];
    }
}
