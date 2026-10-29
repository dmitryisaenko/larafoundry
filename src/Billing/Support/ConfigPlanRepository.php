<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Support;

use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanContract;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanRepositoryContract;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Reads plan definitions from `config('larafoundry-billing.plans.definitions')`
 * (sub-phase B2, decision: config source, no DB seam yet — YAGNI until a plan
 * admin UI exists).
 *
 * Rebound over the core's empty {@see
 * \Dmitryisaenko\LaraFoundry\Billing\Support\ArrayPlanRepository} so callers
 * resolving {@see PlanRepositoryContract} get real plans without knowing the
 * source. The config is operator-controlled (under the host's git, not a request),
 * but a malformed entry must degrade — a broken definition is skipped, never a
 * fatal during boot/checkout and never a half-built plan that grants a feature
 * from garbage. Validation is fail-closed: a plan with an unparseable id or a
 * non-list features array is dropped, not coerced.
 */
final class ConfigPlanRepository implements PlanRepositoryContract
{
    public function __construct(private readonly Config $config) {}

    /**
     * @return list<PlanContract>
     */
    public function all(): array
    {
        $definitions = $this->config->get('larafoundry-billing.plans.definitions', []);

        if (! is_array($definitions)) {
            return [];
        }

        $plans = [];

        foreach ($definitions as $id => $definition) {
            $plan = $this->makePlan($id, $definition);

            if ($plan !== null) {
                $plans[] = $plan;
            }
        }

        return $plans;
    }

    public function find(string $planId): ?PlanContract
    {
        if ($planId === '') {
            return null;
        }

        $definitions = $this->config->get('larafoundry-billing.plans.definitions', []);

        if (! is_array($definitions) || ! array_key_exists($planId, $definitions)) {
            return null;
        }

        // A numeric-string id like '7' is stored by PHP as the int key 7, so the
        // array_key_exists above matched it — but all() drops numeric (list) keys
        // via makePlan's int|string guard. Pass that real key type through makePlan
        // so find() and all() agree: a plan all() would never list isn't findable.
        $realKey = "{$planId}" === (string) (int) $planId ? (int) $planId : $planId;

        return $this->makePlan($realKey, $definitions[$planId]);
    }

    /**
     * Build one plan from its config entry, or null when the entry is malformed.
     *
     * The id is the array key; we accept string keys only (a numeric key means the
     * operator wrote a list, not a keyed map — that loses the stable plan id stored
     * in `Company.plan_id`, so we drop it rather than invent an id).
     */
    private function makePlan(int|string $id, mixed $definition): ?ConfigPlan
    {
        if (! is_string($id) || $id === '' || ! is_array($definition)) {
            return null;
        }

        $name = $definition['name'] ?? null;

        if (! is_string($name) || $name === '') {
            return null;
        }

        return new ConfigPlan(
            id: $id,
            name: $name,
            features: $this->normaliseFeatures($definition['features'] ?? []),
            prices: $this->normalisePrices($definition['prices'] ?? []),
        );
    }

    /**
     * Keep only non-empty string feature slugs, re-indexed as a clean list. A
     * non-array or junk entries collapse to an empty feature set (fail-closed: an
     * unparseable feature is no feature, never an accidental grant).
     *
     * @return list<string>
     */
    private function normaliseFeatures(mixed $features): array
    {
        if (! is_array($features)) {
            return [];
        }

        return array_values(array_filter(
            $features,
            static fn (mixed $feature): bool => is_string($feature) && $feature !== '',
        ));
    }

    /**
     * Normalise the currency => period => amount map, dropping anything that is not
     * a positive integer of minor units. Floats/strings are rejected rather than
     * cast (a price silently truncated from "9.99" to 9 is a billing bug); the
     * operator states cents explicitly.
     *
     * @return array<string, array<string, int>>
     */
    private function normalisePrices(mixed $prices): array
    {
        if (! is_array($prices)) {
            return [];
        }

        $normalised = [];

        foreach ($prices as $currency => $periods) {
            if (! is_string($currency) || $currency === '' || ! is_array($periods)) {
                continue;
            }

            foreach ($periods as $period => $amount) {
                if (! is_string($period) || $period === '' || ! is_int($amount) || $amount < 0) {
                    continue;
                }

                $normalised[$currency][$period] = $amount;
            }
        }

        return $normalised;
    }
}
