<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Support;

use Dmitryisaenko\LaraFoundry\Billing\Contracts\RegionContext;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\Eloquent\Model;

/**
 * Region routing backed by the add-on's config maps (sub-phase B2).
 *
 * Rebound over the core's {@see
 * \Dmitryisaenko\LaraFoundry\Billing\Support\DefaultRegionContext} to add what the
 * free core deliberately omits: a country → currency map and a country → gateway
 * map (`config('larafoundry-billing.region.currencies'|'gateways')`). The country
 * itself is still resolved server-side from the tenant's own `country` column —
 * never a client header or geo-IP (recon finding: don't trust a client-supplied
 * country for billing). An unmapped country falls back to the core's configured
 * default currency and the default gateway, so a host that ships no region map
 * behaves exactly like the free core.
 */
final class ConfiguredRegionContext implements RegionContext
{
    public function __construct(private readonly Config $config) {}

    public function countryFor(Tenant $tenant): ?string
    {
        // Same authoritative source as the core default: the tenant's own column.
        // A `personal`-mode payer reaches this wrapped in a SubjectTenant, so unwrap to
        // the model behind it first — the person's own country must route their region
        // exactly as a company's does. A tenant with no model (or no column) degrades to
        // "unknown" rather than erroring.
        $model = SubjectTenant::model($tenant);

        if (! $model instanceof Model) {
            return null;
        }

        $country = $model->getAttribute('country');

        return is_string($country) && $country !== '' ? $country : null;
    }

    public function currencyFor(Tenant $tenant): string
    {
        $currency = $this->mappedFor($tenant, 'currencies');

        if ($currency !== null) {
            return $currency;
        }

        // Unmapped country (or unknown tenant) → the core's single configured
        // fallback, identical to DefaultRegionContext. The core key, not an add-on
        // one, so host and core agree on the global default.
        $default = $this->config->get('larafoundry.billing.region.default_currency', 'USD');

        return is_string($default) && $default !== '' ? $default : 'USD';
    }

    public function gatewayFor(Tenant $tenant): ?string
    {
        // Null = "use config('larafoundry.billing.gateway.default')", matching the
        // contract: the region layer only overrides for mapped countries.
        return $this->mappedFor($tenant, 'gateways');
    }

    /**
     * Look the tenant's country up in the named region map (`currencies` or
     * `gateways`), or null when the country is unknown or unmapped.
     *
     * The whole map is read as an array and indexed by the country key directly —
     * never via a `…region.{$map}.{$country}` dot-path. A country string is not
     * trusted to be a flat key: a value containing a dot would make config() walk a
     * nested tree instead of matching one entry. Reading the array and indexing by
     * the raw key keeps the lookup a plain map match.
     */
    private function mappedFor(Tenant $tenant, string $map): ?string
    {
        $country = $this->countryFor($tenant);

        if ($country === null) {
            return null;
        }

        $entries = $this->config->get("larafoundry-billing.region.{$map}", []);

        if (! is_array($entries)) {
            return null;
        }

        $value = $entries[$country] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
