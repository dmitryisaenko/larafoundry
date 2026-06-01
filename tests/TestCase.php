<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests;

use Dmitryisaenko\LaraFoundry\LaraFoundryServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            LaraFoundryServiceProvider::class,
        ];
    }
}
