<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tests\Billing;

/**
 * Billing switched on with no gateway and no database: for the suites that exercise
 * the config-backed plan repository and region context. Since v0.45.0 those
 * implementations answer the seam's contracts only while billing is on (off, the
 * seam keeps its empty defaults), so their suites need the switch on.
 */
abstract class EnabledTestCase extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('larafoundry.billing.enabled', true);
    }
}
