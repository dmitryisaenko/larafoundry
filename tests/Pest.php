<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tests\AuthTestCase;
use Dmitryisaenko\LaraFoundry\Tests\TestCase;

// The auth (phase 1.1) suite needs DB migrations + Fortify/Socialite wiring,
// so it binds to the heavier AuthTestCase. Declared first and narrowly so the
// broad binding below does not also claim these folders (Pest forbids two test
// cases on the same path).
uses(AuthTestCase::class)->in('Feature/Auth', 'Unit/Auth', 'Feature/Tenancy', 'Unit/Tenancy', 'Feature/Authorization', 'Unit/Authorization', 'Feature/ActivityLog', 'Unit/ActivityLog', 'Feature/Localization', 'Feature/Admin', 'Feature/Navigation', 'Feature/Media', 'Feature/Billing', 'Feature/Notifications', 'Unit/Notifications', 'Feature/Tickets', 'Unit/Tickets', 'Feature/Profile', 'Feature/Settings', 'Feature/Email', 'Feature/Legal', 'Feature/Seo');

// Everything else keeps the lean, DB-less base case.
uses(TestCase::class)->in(
    'Feature/Middleware',
    'Feature/Rules',
    'Feature/ServiceProviderTest.php',
    'Unit/FilterTest.php',
    'Unit/HasPaginationTest.php',
    'Unit/Navigation',
    'Unit/Dashboard',
    'Unit/Media',
    'Unit/Billing',
    'Unit/Profile',
    'Unit/Settings',
    'Unit/Email',
    'Unit/Seo',
);
