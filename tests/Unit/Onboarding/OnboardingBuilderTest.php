<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Onboarding\Contracts\OnboardingStepProviderInterface;
use Dmitryisaenko\LaraFoundry\Onboarding\Support\OnboardingBuilder;
use Dmitryisaenko\LaraFoundry\Settings\Support\SettingsRepository;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The OnboardingBuilder merges, de-dups, sorts and tallies steps (phase 5.4),
 * the flat twin of the DashboardBuilder. Pure unit tests against fakes — no DB,
 * no routes: the settings read (the dismissed flag) is stubbed so build() never
 * touches the database here.
 */

// A provider that returns a fixed set of steps.
function fakeStepProvider(array $steps, bool $supports = true, int $priority = 0): OnboardingStepProviderInterface
{
    return new class($steps, $supports, $priority) implements OnboardingStepProviderInterface
    {
        public function __construct(
            private array $steps,
            private bool $supported,
            private int $prio,
        ) {}

        public function steps(Authenticatable $user): array
        {
            return $this->steps;
        }

        public function supports(): bool
        {
            return $this->supported;
        }

        public function priority(): int
        {
            return $this->prio;
        }
    };
}

function fakeStepUser(int $id = 1): Authenticatable
{
    return new class($id) implements Authenticatable
    {
        public function __construct(private int $id) {}

        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return $this->id;
        }

        public function getAuthPassword(): string
        {
            return '';
        }

        public function getAuthPasswordName(): string
        {
            return 'password';
        }

        public function getRememberToken(): string
        {
            return '';
        }

        public function setRememberToken($value): void {}

        public function getRememberTokenName(): string
        {
            return 'remember_token';
        }
    };
}

// Stub the settings read so the builder never hits the DB in a unit test: the
// user is treated as "not dismissed" so build() computes the full checklist.
beforeEach(function () {
    app()->instance(SettingsRepository::class, new class extends SettingsRepository
    {
        public function get(string $key, int|string|null $scopeId = null, mixed $default = null): mixed
        {
            return false;
        }
    });
});

function step(string $key, bool $complete, int $order): array
{
    return [
        'key' => $key,
        'title' => 'T '.$key,
        'description' => 'D '.$key,
        'cta_route' => null,
        'cta_label' => null,
        'complete' => $complete,
        'order' => $order,
    ];
}

it('merges steps from multiple providers', function () {
    $builder = (new OnboardingBuilder)
        ->addProvider(fakeStepProvider([step('a', false, 10)]))
        ->addProvider(fakeStepProvider([step('b', false, 20)]));

    $result = $builder->build(fakeStepUser());

    expect(array_column($result['steps'], 'key'))->toBe(['a', 'b'])
        ->and($result['total'])->toBe(2);
});

it('sorts steps by order regardless of provider priority', function () {
    // A higher-priority provider whose step has a LATER order must still sort
    // after a lower-priority provider's earlier-order step — step order wins.
    $builder = (new OnboardingBuilder)
        ->addProvider(fakeStepProvider([step('late', false, 90)], priority: 0))
        ->addProvider(fakeStepProvider([step('early', false, 10)], priority: 10));

    $result = $builder->build(fakeStepUser());

    expect(array_column($result['steps'], 'key'))->toBe(['early', 'late']);
});

it('de-dups steps by key, keeping the first', function () {
    $builder = (new OnboardingBuilder)
        ->addProvider(fakeStepProvider([step('dupe', true, 10)], priority: 0))
        ->addProvider(fakeStepProvider([step('dupe', false, 20)], priority: 10));

    $result = $builder->build(fakeStepUser());

    // Only one 'dupe' survives, and it is the first (complete=true) one.
    expect($result['steps'])->toHaveCount(1)
        ->and($result['steps'][0]['complete'])->toBeTrue();
});

it('excludes a provider whose supports() is false', function () {
    $builder = (new OnboardingBuilder)
        ->addProvider(fakeStepProvider([step('kept', false, 10)], supports: true))
        ->addProvider(fakeStepProvider([step('dropped', false, 20)], supports: false));

    $result = $builder->build(fakeStepUser());

    expect(array_column($result['steps'], 'key'))->toBe(['kept']);
});

it('tallies completed / total / all_complete', function () {
    $builder = (new OnboardingBuilder)
        ->addProvider(fakeStepProvider([
            step('a', true, 10),
            step('b', false, 20),
            step('c', true, 30),
        ]));

    $result = $builder->build(fakeStepUser());

    expect($result['completed'])->toBe(2)
        ->and($result['total'])->toBe(3)
        ->and($result['all_complete'])->toBeFalse()
        ->and($result['dismissed'])->toBeFalse();
});

it('reports all_complete when every step is done', function () {
    $builder = (new OnboardingBuilder)
        ->addProvider(fakeStepProvider([step('a', true, 10), step('b', true, 20)]));

    expect($builder->build(fakeStepUser())['all_complete'])->toBeTrue();
});

it('leaves cta_url null when the route does not exist', function () {
    $builder = (new OnboardingBuilder)
        ->addProvider(fakeStepProvider([
            array_merge(step('a', false, 10), ['cta_route' => 'no.such.route']),
        ]));

    expect($builder->build(fakeStepUser())['steps'][0]['cta_url'])->toBeNull();
});

it('returns null for a guest', function () {
    $builder = (new OnboardingBuilder)
        ->addProvider(fakeStepProvider([step('a', false, 10)]));

    expect($builder->build(null))->toBeNull();
});

it('memoises the built checklist per user', function () {
    $builder = (new OnboardingBuilder)
        ->addProvider(fakeStepProvider([step('a', false, 10)]));

    $user = fakeStepUser();
    $first = $builder->build($user);
    $second = $builder->build($user);

    expect($second)->toBe($first);
});
