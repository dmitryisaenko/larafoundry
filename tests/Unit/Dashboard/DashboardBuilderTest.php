<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Dashboard\Contracts\DashboardWidgetProviderInterface;
use Dmitryisaenko\LaraFoundry\Dashboard\Support\DashboardBuilder;
use Dmitryisaenko\LaraFoundry\Dashboard\Support\DashboardWidget;
use Dmitryisaenko\LaraFoundry\Navigation\Contracts\PolicyChecker;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The DashboardBuilder filters, sorts and merges widgets (phase 3.4), the exact
 * mirror of the MenuBuilder. Pure unit tests against fakes — no DB, no routes.
 */

// A provider that returns a fixed set of widgets for one level.
function fakeWidgetProvider(string $level, array $widgets, int $priority = 0): DashboardWidgetProviderInterface
{
    return new class($level, $widgets, $priority) implements DashboardWidgetProviderInterface
    {
        public function __construct(
            private string $level,
            private array $widgets,
            private int $prio,
        ) {}

        public function getWidgets(string $level, Authenticatable $user): array
        {
            return $this->supports($level) ? $this->widgets : [];
        }

        public function supports(string $level): bool
        {
            return $level === $this->level;
        }

        public function priority(): int
        {
            return $this->prio;
        }
    };
}

// A policy checker that allows only an explicit allow-list of slugs.
function allowWidgets(array $slugs): PolicyChecker
{
    return new class($slugs) implements PolicyChecker
    {
        public function __construct(private array $slugs) {}

        public function check(Authenticatable $user, string $policy): bool
        {
            return in_array($policy, $this->slugs, true);
        }
    };
}

function fakeWidgetUser(int $id = 1): Authenticatable
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

it('shows widgets with no policy', function () {
    $builder = (new DashboardBuilder)
        ->setPolicyChecker(allowWidgets([]))
        ->addProvider(fakeWidgetProvider('admin', [
            new DashboardWidget(key: 'core.users', component: 'UsersWidget', titleKey: 'Users'),
        ]));

    $widgets = $builder->build('admin', fakeWidgetUser());

    expect($widgets)->toHaveCount(1)
        ->and($widgets[0]['key'])->toBe('core.users');
});

it('hides widgets whose policy the user lacks', function () {
    $builder = (new DashboardBuilder)
        ->setPolicyChecker(allowWidgets(['metrics.view']))
        ->addProvider(fakeWidgetProvider('admin', [
            new DashboardWidget(key: 'a', component: 'A', titleKey: 'A', policy: 'metrics.view'),
            new DashboardWidget(key: 'b', component: 'B', titleKey: 'B', policy: 'billing.view'),
        ]));

    $widgets = $builder->build('admin', fakeWidgetUser());

    expect($widgets)->toHaveCount(1)
        ->and($widgets[0]['key'])->toBe('a');
});

it('drops widgets explicitly marked not visible', function () {
    $builder = (new DashboardBuilder)
        ->setPolicyChecker(allowWidgets([]))
        ->addProvider(fakeWidgetProvider('admin', [
            new DashboardWidget(key: 'shown', component: 'A', titleKey: 'A'),
            new DashboardWidget(key: 'hidden', component: 'B', titleKey: 'B', visible: false),
        ]));

    $keys = array_column($builder->build('admin', fakeWidgetUser()), 'key');

    expect($keys)->toBe(['shown']);
});

it('sorts widgets by order ascending', function () {
    $builder = (new DashboardBuilder)
        ->setPolicyChecker(allowWidgets([]))
        ->addProvider(fakeWidgetProvider('admin', [
            new DashboardWidget(key: 'b', component: 'B', titleKey: 'B', order: 20),
            new DashboardWidget(key: 'a', component: 'A', titleKey: 'A', order: 10),
        ]));

    $keys = array_column($builder->build('admin', fakeWidgetUser()), 'key');

    expect($keys)->toBe(['a', 'b']);
});

it('merges widgets from multiple providers ordered by item order, not provider', function () {
    // A higher-priority provider whose widget has a LATER order must still sort
    // after a lower-priority provider's earlier-order widget — item order wins.
    $builder = (new DashboardBuilder)
        ->setPolicyChecker(allowWidgets([]))
        ->addProvider(fakeWidgetProvider('admin', [
            new DashboardWidget(key: 'revenue', component: 'RevenueWidget', titleKey: 'Revenue', order: 7),
        ], priority: 10))
        ->addProvider(fakeWidgetProvider('admin', [
            new DashboardWidget(key: 'core.users', component: 'UsersWidget', titleKey: 'Users', order: 10),
        ], priority: 0));

    $keys = array_column($builder->build('admin', fakeWidgetUser()), 'key');

    expect($keys)->toBe(['revenue', 'core.users']);
});

it('only collects widgets for the requested level', function () {
    $builder = (new DashboardBuilder)
        ->setPolicyChecker(allowWidgets([]))
        ->addProvider(fakeWidgetProvider('admin', [new DashboardWidget(key: 'admin', component: 'A', titleKey: 'A')]))
        ->addProvider(fakeWidgetProvider('tenant', [new DashboardWidget(key: 'tenant', component: 'B', titleKey: 'B')]));

    $keys = array_column($builder->build('admin', fakeWidgetUser()), 'key');

    expect($keys)->toBe(['admin']);
});

it('returns an empty list for a guest', function () {
    $builder = (new DashboardBuilder)
        ->setPolicyChecker(allowWidgets([]))
        ->addProvider(fakeWidgetProvider('admin', [new DashboardWidget(key: 'a', component: 'A', titleKey: 'A')]));

    expect($builder->build('admin', null))->toBe([]);
});

it('memoises the built list per level and user', function () {
    $builder = (new DashboardBuilder)
        ->setPolicyChecker(allowWidgets([]))
        ->addProvider(fakeWidgetProvider('admin', [new DashboardWidget(key: 'a', component: 'A', titleKey: 'A')]));

    $user = fakeWidgetUser();
    $first = $builder->build('admin', $user);
    $second = $builder->build('admin', $user);

    expect($second)->toBe($first);
});

it('ships the title key, the component name and the data through serialization', function () {
    $builder = (new DashboardBuilder)
        ->setPolicyChecker(allowWidgets([]))
        ->addProvider(fakeWidgetProvider('admin', [
            new DashboardWidget(
                key: 'core.users',
                component: 'UsersWidget',
                titleKey: 'Users',
                data: ['total' => 3],
                meta: ['icon' => 'users'],
            ),
        ]));

    $widget = $builder->build('admin', fakeWidgetUser())[0];

    // The title is shipped as a KEY (translated in Vue), never pre-translated.
    expect($widget)->toHaveKey('titleKey', 'Users')
        ->and($widget['component'])->toBe('UsersWidget')
        ->and($widget['data'])->toBe(['total' => 3])
        ->and($widget['meta'])->toBe(['icon' => 'users']);
});
