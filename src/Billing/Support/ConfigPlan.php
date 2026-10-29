<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Support;

use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanContract;

/**
 * An immutable plan backed by a config array (sub-phase B2).
 *
 * The add-on's answer to the core's {@see PlanContract} seam. Built by
 * {@see ConfigPlanRepository} from one entry of
 * `config('larafoundry-billing.plans.definitions')`, so a host changes a plan by
 * editing the add-on's config (under git) rather than a redeploy of the core — the
 * anti-pattern the donor's `config/own.php` tariffs embodied.
 *
 * Two deliberate shape choices vs the donor (recon this milestone):
 *   - Prices are keyed by currency, then period — matching {@see PlanContract::priceFor()}
 *     which takes (period, currency). Country→currency is the {@see
 *     \Dmitryisaenko\LaraFoundry\Billing\Contracts\RegionContext}'s job, not the
 *     plan's, so a plan never knows about countries.
 *   - `name` is a human-readable display string used as an i18n key the frontend
 *     resolves with `$t()` (the core's English-as-key convention, e.g. lang/frontend),
 *     NOT a baked single-language label. Feature slugs share the RBAC permission
 *     vocabulary (phase 1.3) so the B6 entitlement loop reads one dictionary.
 */
final class ConfigPlan implements PlanContract
{
    /**
     * @param  list<string>  $features  Feature slugs in the RBAC permission vocabulary.
     * @param  array<string, array<string, int>>  $prices  currency => period => minor units.
     */
    public function __construct(
        private readonly string $id,
        private readonly string $name,
        private readonly array $features,
        private readonly array $prices,
    ) {}

    public function id(): string
    {
        return $this->id;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return list<string>
     */
    public function features(): array
    {
        return $this->features;
    }

    public function priceFor(string $period, string $currency): ?int
    {
        // Prices are stored currency-first (a plan is "sold in USD/EUR/UAH"), then
        // by period. A missing currency or period means the plan isn't sold in that
        // combination — return null, never 0, so a free tier and an unsold currency
        // stay distinguishable. The repository has already coerced amounts to int.
        $amount = $this->prices[$currency][$period] ?? null;

        return is_int($amount) ? $amount : null;
    }
}
