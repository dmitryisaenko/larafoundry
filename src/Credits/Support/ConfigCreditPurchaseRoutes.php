<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Credits\Support;

use Dmitryisaenko\LaraFoundry\Credits\Contracts\CreditPurchaseRoutes;
use Dmitryisaenko\LaraFoundry\Support\RequestCountry;

/**
 * The default purchase routes (v0.58.0), from
 * `larafoundry.credits.purchases.routes`:
 *
 *   'routes' => [
 *       'default' => ['gateway' => 'paddle'],
 *       'countries' => [
 *           'UA' => ['gateway' => 'liqpay', 'currency' => 'UAH', 'bonus_percent' => 0],
 *       ],
 *   ],
 *
 * The country map is read whole and indexed by the code (never a dot path built
 * from it). A country that is not listed, an unknown one, and a listed entry
 * without a gateway all take `default`; without a usable `default` the gateway
 * is `paddle`, the one the core sold credits through before routes existed.
 */
class ConfigCreditPurchaseRoutes implements CreditPurchaseRoutes
{
    public const FALLBACK_GATEWAY = 'paddle';

    public function for(?string $country): CreditPurchaseRoute
    {
        $code = RequestCountry::normalise($country);
        $routes = config('larafoundry.credits.purchases.routes', []);
        $routes = is_array($routes) ? $routes : [];

        if ($code !== null) {
            $countries = is_array($routes['countries'] ?? null) ? $routes['countries'] : [];
            $entry = $this->entry($countries, $code);

            if ($entry !== null && ($route = CreditPurchaseRoute::fromArray($entry)) !== null) {
                return $route;
            }
        }

        $default = is_array($routes['default'] ?? null) ? $routes['default'] : [];

        return CreditPurchaseRoute::fromArray($default) ?? new CreditPurchaseRoute(self::FALLBACK_GATEWAY);
    }

    /**
     * The entry for a code, whatever case the settings wrote it in.
     *
     * @param  array<mixed, mixed>  $countries
     * @return array<string, mixed>|null
     */
    private function entry(array $countries, string $code): ?array
    {
        foreach ($countries as $key => $entry) {
            if (is_string($key) && strtoupper(trim($key)) === $code && is_array($entry)) {
                return $entry;
            }
        }

        return null;
    }
}
