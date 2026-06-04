<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Contracts;

/**
 * A subscription plan (phase 3.1, decision D3.1-b).
 *
 * An interface, not a model: the FREE core does NOT decide where plans live. The
 * donor hardcoded tariffs as a `config/own.php` array — a redeploy to change a
 * price and no price history. We refuse that. `Company.plan_id` is a plain
 * string identifier; the source of plan objects sits behind {@see
 * PlanRepositoryContract}, so the paid add-on (or a host) can back plans with
 * config, a `plans` table, or the gateway's own catalogue without the core
 * caring.
 *
 * Pricing is intentionally parameterised by period and region rather than a
 * single scalar: the gateway-agnostic, multi-currency story is the differentiator
 * for non-US markets (decision in memory `monetization-model`). The default core
 * repository returns no priced plans (billing is off out of the box).
 */
interface PlanContract
{
    /**
     * The stable identifier stored in `Company.plan_id` (e.g. 'free', 'pro').
     */
    public function id(): string;

    /**
     * Human-readable name for display (the add-on/host localises this).
     */
    public function name(): string;

    /**
     * Feature slugs this plan grants, in the same vocabulary as RBAC permission
     * slugs (phase 1.3) — this is the Billing↔RBAC loop: {@see
     * EntitlementResolver} maps "does the company's plan allow feature X".
     *
     * @return list<string>
     */
    public function features(): array;

    /**
     * Price in minor units (cents) for the given billing period and currency, or
     * null when the plan has no price for that combination (e.g. a free plan, or
     * a currency this plan isn't sold in). Returning a primitive keeps the core
     * free of a money library; the add-on may wrap it.
     */
    public function priceFor(string $period, string $currency): ?int;
}
