<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Contracts;

use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;

/**
 * Region resolution for billing (phase 3.1, decision D3.1-c).
 *
 * Only a contract-seam in the FREE core, with a thin default ({@see
 * \Dmitryisaenko\LaraFoundry\Billing\Support\DefaultRegionContext}) that derives
 * everything from the tenant's `country` column — server-side, never a client
 * header or geo-IP (recon finding: don't trust client-supplied country). The
 * real per-country pricing and gateway selection live in the paid add-on / host,
 * which rebind this contract.
 *
 * The core hardcodes no country (core-scope: "the core never hardcodes a
 * country"). It just answers three questions the gateway layer needs.
 */
interface RegionContext
{
    /**
     * ISO 3166-1 alpha-2 country code for the tenant, or null when unknown.
     * The default reads `Company.country`; a host may override (VAT address, IP).
     */
    public function countryFor(Tenant $tenant): ?string;

    /**
     * ISO 4217 currency code to bill the tenant in (e.g. 'USD', 'EUR', 'UAH').
     * The default returns the configured fallback currency; the add-on/host maps
     * country → currency.
     */
    public function currencyFor(Tenant $tenant): string;

    /**
     * The payment-gateway driver name to use for the tenant's region, or null to
     * fall back to `config('larafoundry.billing.gateway.default')`. This is where
     * a host routes UA tenants to a local PSP and everyone else to the global
     * default, without the core knowing any country.
     */
    public function gatewayFor(Tenant $tenant): ?string;
}
