<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Billing\Contracts\EntitlementResolver;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanContract;
use Dmitryisaenko\LaraFoundry\Billing\Contracts\PlanRepositoryContract;
use Dmitryisaenko\LaraFoundry\Billing\LaraFoundryBilling;
use Dmitryisaenko\LaraFoundry\Billing\Support\NullPaymentGateway;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
 * LaraFoundryBilling is the one entry point call sites use, so they never depend
 * on whether a real gateway is installed. In the free core every accessor
 * resolves to the default/null implementation; these confirm that and prove the
 * container bindings from registerBilling() are live.
 */

function facadeCompany(array $attributes = []): Company
{
    $owner = User::create([
        'name' => 'Owner',
        'email' => 'owner'.uniqid().'@x.test',
        'password' => 'secret-pass',
    ]);

    return Company::create(array_merge([
        'name' => 'Acme',
        'slug' => 'acme-'.uniqid(),
        'created_by_id' => $owner->id,
    ], $attributes));
}

it('reports billing disabled by default', function () {
    config()->set('larafoundry.billing.enabled', false);

    expect(LaraFoundryBilling::enabled())->toBeFalse();
});

it('resolves the null gateway in the free core', function () {
    $company = facadeCompany();

    expect(LaraFoundryBilling::gateway())->toBeInstanceOf(NullPaymentGateway::class)
        ->and(LaraFoundryBilling::gateway($company))->toBeInstanceOf(NullPaymentGateway::class);
});

it('returns no plan for a company in the free core', function () {
    $company = facadeCompany();

    expect(LaraFoundryBilling::planFor($company))->toBeNull();
});

it('resolves a plan through a rebound repository (add-on path)', function () {
    $plan = new class implements PlanContract
    {
        public function id(): string
        {
            return 'pro';
        }

        public function name(): string
        {
            return 'Pro';
        }

        public function features(): array
        {
            return ['reports.export'];
        }

        public function priceFor(string $period, string $currency): ?int
        {
            return $period === 'monthly' ? 999 : null;
        }
    };

    app()->bind(PlanRepositoryContract::class, function () use ($plan) {
        return new class($plan) implements PlanRepositoryContract
        {
            public function __construct(private PlanContract $plan) {}

            public function all(): array
            {
                return [$this->plan];
            }

            public function find(string $planId): ?PlanContract
            {
                return $planId === $this->plan->id() ? $this->plan : null;
            }
        };
    });

    // plan_id is unfillable, so set it the way the add-on would — server-side.
    $company = facadeCompany();
    $company->forceFill(['plan_id' => 'pro'])->save();

    expect(LaraFoundryBilling::planFor($company->refresh())?->name())->toBe('Pro');
});

it('allows every feature through the default entitlement resolver', function () {
    $company = facadeCompany();

    expect(LaraFoundryBilling::allows($company, 'anything.at.all'))->toBeTrue();
});

it('honours a rebound entitlement resolver (the Billing-RBAC loop)', function () {
    app()->bind(EntitlementResolver::class, function () {
        return new class implements EntitlementResolver
        {
            public function allows($tenant, string $feature): bool
            {
                return $feature === 'reports.export';
            }
        };
    });

    $company = facadeCompany();

    expect(LaraFoundryBilling::allows($company, 'reports.export'))->toBeTrue()
        ->and(LaraFoundryBilling::allows($company, 'reports.delete'))->toBeFalse();
});
