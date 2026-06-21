<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Navigation\Contracts\MenuProviderInterface;
use Dmitryisaenko\LaraFoundry\Navigation\Contracts\PolicyChecker;
use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuBuilder;
use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuItem;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The MenuBuilder filters, sorts and merges menu items (phase 2.3). These are
 * pure unit tests against fakes — no DB, no routes.
 */

// A provider that returns a fixed set of items for one level.
function fakeProvider(string $level, array $items, int $priority = 0): MenuProviderInterface
{
    return new class($level, $items, $priority) implements MenuProviderInterface
    {
        public function __construct(
            private string $level,
            private array $items,
            private int $prio,
        ) {}

        public function getMenuItems(string $level): array
        {
            return $this->supports($level) ? $this->items : [];
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
function allowOnly(array $slugs): PolicyChecker
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

function fakeUser(): Authenticatable
{
    return new class implements Authenticatable
    {
        public function getAuthIdentifierName(): string
        {
            return 'id';
        }

        public function getAuthIdentifier(): int
        {
            return 1;
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

it('shows items with no policy', function () {
    $builder = (new MenuBuilder)
        ->setPolicyChecker(allowOnly([]))
        ->addProvider(fakeProvider('admin', [new MenuItem(labelKey: 'Users', url: '/u')]));

    $menu = $builder->build('admin', fakeUser());

    expect($menu)->toHaveCount(1)
        ->and($menu[0]['labelKey'])->toBe('Users');
});

it('hides items whose policy the user lacks', function () {
    $builder = (new MenuBuilder)
        ->setPolicyChecker(allowOnly(['roles.view']))
        ->addProvider(fakeProvider('tenant', [
            new MenuItem(labelKey: 'Roles', url: '/r', policy: 'roles.view'),
            new MenuItem(labelKey: 'Billing', url: '/b', policy: 'billing.view'),
        ]));

    $menu = $builder->build('tenant', fakeUser());

    expect($menu)->toHaveCount(1)
        ->and($menu[0]['labelKey'])->toBe('Roles');
});

it('sorts items by order ascending', function () {
    $builder = (new MenuBuilder)
        ->setPolicyChecker(allowOnly([]))
        ->addProvider(fakeProvider('admin', [
            new MenuItem(labelKey: 'B', url: '/b', order: 20),
            new MenuItem(labelKey: 'A', url: '/a', order: 10),
        ]));

    $labels = array_column($builder->build('admin', fakeUser()), 'labelKey');

    expect($labels)->toBe(['A', 'B']);
});

it('merges items from multiple providers', function () {
    $builder = (new MenuBuilder)
        ->setPolicyChecker(allowOnly([]))
        ->addProvider(fakeProvider('admin', [new MenuItem(labelKey: 'A', url: '/a', order: 10)]))
        ->addProvider(fakeProvider('admin', [new MenuItem(labelKey: 'B', url: '/b', order: 20)]));

    expect($builder->build('admin', fakeUser()))->toHaveCount(2);
});

it('only collects items for the requested level', function () {
    $builder = (new MenuBuilder)
        ->setPolicyChecker(allowOnly([]))
        ->addProvider(fakeProvider('admin', [new MenuItem(labelKey: 'AdminOnly', url: '/a')]))
        ->addProvider(fakeProvider('tenant', [new MenuItem(labelKey: 'TenantOnly', url: '/t')]));

    $labels = array_column($builder->build('tenant', fakeUser()), 'labelKey');

    expect($labels)->toBe(['TenantOnly']);
});

it('filters submenus recursively and drops empty groups', function () {
    $group = new MenuItem(labelKey: 'Group', submenu: [
        new MenuItem(labelKey: 'Allowed', url: '/a', policy: 'ok'),
        new MenuItem(labelKey: 'Denied', url: '/d', policy: 'nope'),
    ]);

    $emptyGroup = new MenuItem(labelKey: 'Empty', submenu: [
        new MenuItem(labelKey: 'X', url: '/x', policy: 'nope'),
    ]);

    $builder = (new MenuBuilder)
        ->setPolicyChecker(allowOnly(['ok']))
        ->addProvider(fakeProvider('admin', [$group, $emptyGroup]));

    $menu = $builder->build('admin', fakeUser());

    expect($menu)->toHaveCount(1)
        ->and($menu[0]['labelKey'])->toBe('Group')
        ->and($menu[0]['submenu'])->toHaveCount(1)
        ->and($menu[0]['submenu'][0]['labelKey'])->toBe('Allowed');
});

it('merges top-level pure groups that share a labelKey', function () {
    // The core ships a "My company" group; the host grows the same group from
    // its own provider. They must collapse into one parent with both children.
    $builder = (new MenuBuilder)
        ->setPolicyChecker(allowOnly([]))
        ->addProvider(fakeProvider('tenant', [
            new MenuItem(labelKey: 'My company', order: 90, submenu: [
                new MenuItem(labelKey: 'Employees', url: '/e', order: 80),
            ]),
        ]))
        ->addProvider(fakeProvider('tenant', [
            new MenuItem(labelKey: 'My company', order: 10, submenu: [
                new MenuItem(labelKey: 'Dashboard', url: '/d', order: 10),
            ]),
        ]));

    $menu = $builder->build('tenant', fakeUser());

    expect($menu)->toHaveCount(1)
        ->and($menu[0]['labelKey'])->toBe('My company')
        // Lowest order among merged copies wins (host's 10).
        ->and($menu[0]['order'])->toBe(10)
        // Merged submenu, sorted by child order.
        ->and(array_column($menu[0]['submenu'], 'labelKey'))->toBe(['Dashboard', 'Employees']);
});

it('applies policy filtering inside a merged group', function () {
    $builder = (new MenuBuilder)
        ->setPolicyChecker(allowOnly(['ok']))
        ->addProvider(fakeProvider('tenant', [
            new MenuItem(labelKey: 'My company', submenu: [
                new MenuItem(labelKey: 'Allowed', url: '/a', policy: 'ok'),
            ]),
        ]))
        ->addProvider(fakeProvider('tenant', [
            new MenuItem(labelKey: 'My company', submenu: [
                new MenuItem(labelKey: 'Denied', url: '/d', policy: 'nope'),
            ]),
        ]));

    $menu = $builder->build('tenant', fakeUser());

    expect($menu)->toHaveCount(1)
        ->and(array_column($menu[0]['submenu'], 'labelKey'))->toBe(['Allowed']);
});

it('drops a merged group whose entire submenu is filtered out', function () {
    $builder = (new MenuBuilder)
        ->setPolicyChecker(allowOnly([]))
        ->addProvider(fakeProvider('tenant', [
            new MenuItem(labelKey: 'My company', submenu: [
                new MenuItem(labelKey: 'X', url: '/x', policy: 'nope'),
            ]),
        ]))
        ->addProvider(fakeProvider('tenant', [
            new MenuItem(labelKey: 'My company', submenu: [
                new MenuItem(labelKey: 'Y', url: '/y', policy: 'nope'),
            ]),
        ]));

    expect($builder->build('tenant', fakeUser()))->toBe([]);
});

it('does not merge two leaf items that share a labelKey', function () {
    // Same label, but they are real links (have a url), not pure groups.
    $builder = (new MenuBuilder)
        ->setPolicyChecker(allowOnly([]))
        ->addProvider(fakeProvider('admin', [new MenuItem(labelKey: 'Same', url: '/one')]))
        ->addProvider(fakeProvider('admin', [new MenuItem(labelKey: 'Same', url: '/two')]));

    expect($builder->build('admin', fakeUser()))->toHaveCount(2);
});

it('does not mutate a provider MenuItem instance when merging', function () {
    $shared = new MenuItem(labelKey: 'My company', submenu: [
        new MenuItem(labelKey: 'Employees', url: '/e'),
    ]);

    $builder = (new MenuBuilder)
        ->setPolicyChecker(allowOnly([]))
        ->addProvider(fakeProvider('tenant', [$shared]))
        ->addProvider(fakeProvider('tenant', [
            new MenuItem(labelKey: 'My company', submenu: [
                new MenuItem(labelKey: 'Dashboard', url: '/d'),
            ]),
        ]));

    $builder->build('tenant', fakeUser());

    // The original provider instance still has only its own child.
    expect($shared->submenu)->toHaveCount(1)
        ->and($shared->submenu[0]->labelKey)->toBe('Employees');
});

it('drops items explicitly marked not visible', function () {
    $builder = (new MenuBuilder)
        ->setPolicyChecker(allowOnly([]))
        ->addProvider(fakeProvider('admin', [
            new MenuItem(labelKey: 'Shown', url: '/s'),
            new MenuItem(labelKey: 'Hidden', url: '/h', visible: false),
        ]));

    $labels = array_column($builder->build('admin', fakeUser()), 'labelKey');

    expect($labels)->toBe(['Shown']);
});

it('memoises the built tree per level and user', function () {
    $builder = (new MenuBuilder)
        ->setPolicyChecker(allowOnly([]))
        ->addProvider(fakeProvider('admin', [new MenuItem(labelKey: 'A', url: '/a')]));

    $user = fakeUser();
    $first = $builder->build('admin', $user);
    $second = $builder->build('admin', $user);

    expect($second)->toBe($first);
});

it('ships the label key, not a translated string', function () {
    $builder = (new MenuBuilder)
        ->setPolicyChecker(allowOnly([]))
        ->addProvider(fakeProvider('admin', [new MenuItem(labelKey: 'Users', url: '/u')]));

    expect($builder->build('admin', fakeUser())[0])->toHaveKey('labelKey', 'Users');
});
