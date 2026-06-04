<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Support;

use Dmitryisaenko\LaraFoundry\Billing\Contracts\RegionContext;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * The FREE core's region resolver (phase 3.1, decision D3.1-c).
 *
 * Derives the country from the tenant's own `country` column — server-side,
 * authoritative, never a client header or geo-IP (recon: don't trust a
 * client-supplied country for billing). Currency falls back to the configured
 * default; gateway selection defers to config. It maps no country to anything
 * special — the core hardcodes no country (core-scope). The paid add-on / host
 * rebinds {@see RegionContext} to add per-country currency and gateway routing.
 */
class DefaultRegionContext implements RegionContext
{
    public function countryFor(Tenant $tenant): ?string
    {
        // Only Eloquent tenants carry a `country` column; guard so a non-model
        // tenant (or one without the column) degrades to "unknown" rather than
        // erroring.
        if (! $tenant instanceof Model) {
            return null;
        }

        $country = $tenant->getAttribute('country');

        return is_string($country) && $country !== '' ? $country : null;
    }

    public function currencyFor(Tenant $tenant): string
    {
        // No per-country mapping in the free core — one configured currency for
        // everyone. The add-on/host overrides this with a country→currency map.
        return config('larafoundry.billing.region.default_currency', 'USD');
    }

    public function gatewayFor(Tenant $tenant): ?string
    {
        // Null = "use the configured default gateway". The free core never routes
        // by region; a host returns a driver name here to send, say, UA tenants
        // to a local PSP.
        return null;
    }
}
