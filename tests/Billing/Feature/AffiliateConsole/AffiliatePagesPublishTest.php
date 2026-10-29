<?php

declare(strict_types=1);

/**
 * Guards the Ф4.4 admin frontend deliverables (render-tested in the host): every affiliate
 * console page the controllers render exists under the admin-pages publish tree. Locale
 * parity is asserted globally by the owner BillingPagesPublishTest over the same en/uk files.
 */
it('ships every affiliate console page the controllers render', function () {
    $dir = dirname(__DIR__, 4).'/resources/js/Pages/Admin';

    foreach (['Affiliates/Index', 'Payouts/Index'] as $page) {
        expect(file_exists("{$dir}/{$page}.vue"))->toBeTrue("Missing page: {$page}.vue");
    }
});
