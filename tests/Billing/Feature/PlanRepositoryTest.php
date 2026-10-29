<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanContract;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanRepositoryContract;
use Dmitryisaenko\LaraFoundry\Billing\Support\ConfigPlan;
use Dmitryisaenko\LaraFoundry\Billing\Support\ConfigPlanRepository;

/**
 * Set the plan definitions config, then resolve the repository the same way a
 * call site does. The add-on provider has already rebound PlanRepositoryContract
 * to ConfigPlanRepository, so resolving the contract proves both the binding and
 * the reader.
 */
function planRepo(array $definitions): PlanRepositoryContract
{
    config()->set('larafoundry-billing.plans.definitions', $definitions);

    return app(PlanRepositoryContract::class);
}

it('binds the contract to the config-backed repository', function () {
    expect(app(PlanRepositoryContract::class))->toBeInstanceOf(ConfigPlanRepository::class);
});

it('reads every defined plan via all()', function () {
    $plans = planRepo([
        'free' => ['name' => 'Free', 'features' => ['core.access'], 'prices' => []],
        'pro' => ['name' => 'Pro', 'features' => ['core.access', 'api.access'], 'prices' => ['USD' => ['monthly' => 1900]]],
    ])->all();

    expect($plans)->toHaveCount(2)
        ->and($plans[0])->toBeInstanceOf(ConfigPlan::class)
        ->and($plans[0]->id())->toBe('free')
        ->and($plans[1]->id())->toBe('pro')
        ->and($plans[1]->priceFor('monthly', 'USD'))->toBe(1900);
});

it('returns a list (sequential keys) from all(), not the config map', function () {
    $plans = planRepo([
        'free' => ['name' => 'Free', 'features' => [], 'prices' => []],
        'pro' => ['name' => 'Pro', 'features' => [], 'prices' => []],
    ])->all();

    expect(array_keys($plans))->toBe([0, 1]);
});

it('finds a plan by its id', function () {
    $plan = planRepo([
        'pro' => ['name' => 'Pro', 'features' => ['api.access'], 'prices' => ['EUR' => ['yearly' => 19000]]],
    ])->find('pro');

    expect($plan)->toBeInstanceOf(PlanContract::class)
        ->and($plan->id())->toBe('pro')
        ->and($plan->features())->toBe(['api.access'])
        ->and($plan->priceFor('yearly', 'EUR'))->toBe(19000);
});

it('returns null when finding an unknown or retired plan', function () {
    $repo = planRepo(['pro' => ['name' => 'Pro', 'features' => [], 'prices' => []]]);

    expect($repo->find('enterprise'))->toBeNull();
});

it('returns null when finding with an empty id', function () {
    $repo = planRepo(['pro' => ['name' => 'Pro', 'features' => [], 'prices' => []]]);

    expect($repo->find(''))->toBeNull();
});

it('yields no plans when the config is empty', function () {
    expect(planRepo([])->all())->toBe([]);
});

it('degrades to no plans when the definitions config is not an array', function () {
    config()->set('larafoundry-billing.plans.definitions', 'broken');

    expect(app(PlanRepositoryContract::class)->all())->toBe([])
        ->and(app(PlanRepositoryContract::class)->find('pro'))->toBeNull();
});

it('skips a plan defined under a numeric (list) key', function () {
    // A numeric key means the operator wrote a list, losing the stable plan id
    // that lands in Company.plan_id — drop it rather than invent an id.
    $plans = planRepo([
        ['name' => 'No id', 'features' => [], 'prices' => []],
        'pro' => ['name' => 'Pro', 'features' => [], 'prices' => []],
    ])->all();

    expect($plans)->toHaveCount(1)
        ->and($plans[0]->id())->toBe('pro');
});

it('does not find a list-defined plan by its numeric-string index, matching all()', function () {
    // PHP stores the string key '0' as int 0, so a naive array_key_exists('0', ...)
    // would match the first list entry — but all() never lists it. find() must
    // agree: a plan all() drops is not findable.
    $repo = planRepo([
        ['name' => 'No id', 'features' => [], 'prices' => []],
        'pro' => ['name' => 'Pro', 'features' => [], 'prices' => []],
    ]);

    expect($repo->find('0'))->toBeNull()
        ->and($repo->find('pro'))->not->toBeNull();
});

it('skips a plan with a missing or blank name', function () {
    $plans = planRepo([
        'noname' => ['features' => ['core.access'], 'prices' => []],
        'blank' => ['name' => '', 'features' => [], 'prices' => []],
        'pro' => ['name' => 'Pro', 'features' => [], 'prices' => []],
    ])->all();

    expect($plans)->toHaveCount(1)
        ->and($plans[0]->id())->toBe('pro');
});

it('drops non-string and blank feature slugs, never coercing junk into a grant', function () {
    $plan = planRepo([
        'pro' => ['name' => 'Pro', 'features' => ['api.access', '', 123, null, 'reports.advanced'], 'prices' => []],
    ])->find('pro');

    expect($plan->features())->toBe(['api.access', 'reports.advanced']);
});

it('collapses a non-array features entry to no features', function () {
    $plan = planRepo([
        'pro' => ['name' => 'Pro', 'features' => 'core.access', 'prices' => []],
    ])->find('pro');

    expect($plan->features())->toBe([]);
});

it('rejects non-integer prices rather than truncating them', function () {
    // A float silently cast from 9.99 to 9 is a billing bug; the operator must
    // state minor units as an integer. Junk amounts and negatives are dropped.
    $plan = planRepo([
        'pro' => ['name' => 'Pro', 'features' => [], 'prices' => [
            'USD' => ['monthly' => 1999, 'yearly' => 19.99, 'quarterly' => 'free', 'weekly' => -100],
        ]],
    ])->find('pro');

    expect($plan->priceFor('monthly', 'USD'))->toBe(1999)
        ->and($plan->priceFor('yearly', 'USD'))->toBeNull()
        ->and($plan->priceFor('quarterly', 'USD'))->toBeNull()
        ->and($plan->priceFor('weekly', 'USD'))->toBeNull();
});

it('drops a price entry whose currency or periods are malformed', function () {
    $plan = planRepo([
        'pro' => ['name' => 'Pro', 'features' => [], 'prices' => [
            'USD' => ['monthly' => 1900],
            '' => ['monthly' => 500],
            'EUR' => 'not-an-array',
        ]],
    ])->find('pro');

    expect($plan->priceFor('monthly', 'USD'))->toBe(1900)
        ->and($plan->priceFor('monthly', 'EUR'))->toBeNull();
});

it('allows a zero price (a free combination stated explicitly)', function () {
    $plan = planRepo([
        'intro' => ['name' => 'Intro', 'features' => [], 'prices' => ['USD' => ['monthly' => 0]]],
    ])->find('intro');

    expect($plan->priceFor('monthly', 'USD'))->toBe(0);
});
