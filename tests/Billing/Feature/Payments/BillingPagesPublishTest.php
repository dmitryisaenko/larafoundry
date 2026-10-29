<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\BillingServiceProvider;

/**
 * Guards the B7a frontend deliverables that have no vitest of their own (Variant A ships
 * the pages into the host, so they are render-tested there): the publish tag is wired,
 * every page the controllers render exists, and every UI string is translated in both
 * shipped locales.
 */
it('publishes the owner billing pages under the pages tag', function () {
    $paths = BillingServiceProvider::pathsToPublish(
        BillingServiceProvider::class,
        'larafoundry-billing-pages',
    );

    expect($paths)->not->toBeEmpty();

    $source = (string) array_key_first($paths);
    $dest = (string) reset($paths);

    expect($source)->toContain('resources')
        ->and($source)->toContain('Pages')
        ->and($dest)->toContain('Pages');
});

it('ships every owner billing page the controllers render', function () {
    $dir = dirname(__DIR__, 4).'/resources/js/Pages/Billing';

    foreach (['Pricing', 'Checkout', 'PaymentHistory', 'Blocked'] as $page) {
        expect(file_exists("{$dir}/{$page}.vue"))->toBeTrue("Missing page: {$page}.vue");
    }
});

it('translates every UI string key in both shipped locales', function () {
    $base = dirname(__DIR__, 4).'/lang/frontend';

    $en = json_decode((string) file_get_contents("{$base}/en.json"), true);
    $uk = json_decode((string) file_get_contents("{$base}/uk.json"), true);

    expect($en)->toBeArray()->not->toBeEmpty()
        ->and($uk)->toBeArray()->not->toBeEmpty()
        ->and(array_diff_key($en, $uk))->toBe([])   // no English key missing a Ukrainian value
        ->and(array_diff_key($uk, $en))->toBe([]);   // and no orphan Ukrainian key
});
