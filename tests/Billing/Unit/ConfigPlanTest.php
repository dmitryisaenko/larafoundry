<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanContract;
use Dmitryisaenko\LaraFoundry\Billing\Support\ConfigPlan;

it('exposes its identity and feature slugs', function () {
    $plan = new ConfigPlan(
        id: 'pro',
        name: 'Pro',
        features: ['core.access', 'api.access'],
        prices: ['USD' => ['monthly' => 1900]],
    );

    expect($plan)->toBeInstanceOf(PlanContract::class)
        ->and($plan->id())->toBe('pro')
        ->and($plan->name())->toBe('Pro')
        ->and($plan->features())->toBe(['core.access', 'api.access']);
});

it('returns the price in minor units for a currency and period', function () {
    $plan = new ConfigPlan(
        id: 'pro',
        name: 'Pro',
        features: [],
        prices: [
            'USD' => ['monthly' => 1900, 'yearly' => 19000],
            'UAH' => ['monthly' => 79000],
        ],
    );

    expect($plan->priceFor('monthly', 'USD'))->toBe(1900)
        ->and($plan->priceFor('yearly', 'USD'))->toBe(19000)
        ->and($plan->priceFor('monthly', 'UAH'))->toBe(79000);
});

it('returns null for a currency the plan is not sold in', function () {
    $plan = new ConfigPlan(id: 'pro', name: 'Pro', features: [], prices: ['USD' => ['monthly' => 1900]]);

    expect($plan->priceFor('monthly', 'EUR'))->toBeNull();
});

it('returns null for a period the plan does not offer in that currency', function () {
    $plan = new ConfigPlan(id: 'pro', name: 'Pro', features: [], prices: ['USD' => ['monthly' => 1900]]);

    expect($plan->priceFor('yearly', 'USD'))->toBeNull();
});

it('returns null for a free plan with no prices, never zero', function () {
    $plan = new ConfigPlan(id: 'free', name: 'Free', features: ['core.access'], prices: []);

    // null (not 0) keeps "free, no charge" distinct from "priced at zero": a caller
    // that sees null knows there is nothing to charge for this combination.
    expect($plan->priceFor('monthly', 'USD'))->toBeNull();
});
