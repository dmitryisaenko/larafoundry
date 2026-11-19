<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Contracts;

use Dmitryisaenko\LaraFoundry\Credits\Support\ConfigCreditPurchaseRoutes;
use Dmitryisaenko\LaraFoundry\Credits\Support\CreditPurchaseRoute;

/**
 * Which gateway a buyer pays through (v0.58.0), by the country they connect
 * from - a local gateway for its own country, a global one for the rest.
 *
 * The answer is binding, not a hint: a gateway refuses to open a checkout for a
 * country routed elsewhere, so a buyer cannot reach it by calling its address
 * directly. The host's page asks the same question to decide what to draw.
 *
 * The default, {@see ConfigCreditPurchaseRoutes}, reads
 * `larafoundry.credits.purchases.routes`; a host whose routes are admin settings
 * binds its own.
 */
interface CreditPurchaseRoutes
{
    /**
     * The route for a buyer from `$country` (ISO 3166-1 alpha-2), null when the
     * country is unknown - which takes the default route.
     */
    public function for(?string $country): CreditPurchaseRoute;
}
