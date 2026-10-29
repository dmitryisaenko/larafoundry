<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\BillingServiceProvider;

/**
 * Guards the B7b admin frontend deliverables (no vitest of their own — Variant A ships the
 * pages into the host, render-tested there): the admin publish tag is wired and every page
 * the admin controllers render exists. Frontend locale parity is asserted globally by the
 * owner BillingPagesPublishTest over the same en/uk files.
 */
it('publishes the admin console pages under the admin-pages tag', function () {
    $paths = BillingServiceProvider::pathsToPublish(
        BillingServiceProvider::class,
        'larafoundry-billing-admin-pages',
    );

    expect($paths)->not->toBeEmpty();

    $source = (string) array_key_first($paths);
    $dest = (string) reset($paths);

    expect($source)->toContain('Pages')
        ->and($source)->toContain('Admin')
        ->and($dest)->toContain('Admin');
});

it('ships every admin console page the controllers render', function () {
    $dir = dirname(__DIR__, 4).'/resources/js/Pages/Admin';

    $pages = [
        'Payments/Index',
        'PromoCodes/Index',
        'PromoCodes/Create',
        'PromoCodes/Edit',
    ];

    foreach ($pages as $page) {
        expect(file_exists("{$dir}/{$page}.vue"))->toBeTrue("Missing page: {$page}.vue");
    }
});
