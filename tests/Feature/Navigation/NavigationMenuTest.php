<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Navigation\LaraFoundryNavigation;
use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuBuilder;
use Dmitryisaenko\LaraFoundry\Navigation\Support\RbacPolicyChecker;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Dmitryisaenko\LaraFoundry\Tenancy\Resolvers\SessionTenantResolver;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function navAdmin(string $email = 'admin@x.test'): User
{
    return User::create([
        'name' => 'Admin', 'email' => $email, 'password' => 'secret-pass',
        'email_verified_at' => now(), 'is_admin' => true,
    ]);
}

function navUser(string $email = 'user@x.test'): User
{
    return User::create([
        'name' => 'User', 'email' => $email, 'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

function navCompanyOwnedBy(User $owner): Company
{
    $company = Company::create([
        'name' => 'Acme', 'slug' => 'acme-'.Str::random(6), 'created_by_id' => $owner->id,
    ]);
    $company->addEmployee($owner, addedById: $owner->id, isOwner: true);

    return $company;
}

function navActivate(User $user, Company $company): void
{
    $store = new Store('larafoundry_session', new ArraySessionHandler(120), str_pad('nav'.$user->id, 40, 'a'));
    $store->start();
    app()->instance('session.store', $store);
    request()->setLaravelSession($store);

    app(SessionTenantResolver::class)->setCurrent($user, $company);
}

it('builds the admin menu for a super-admin', function () {
    $admin = navAdmin();

    $menu = app(MenuBuilder::class)->build('admin', $admin);
    $labels = array_column($menu, 'labelKey');

    // The Dashboard (phase 3.4) is the first admin item (order 5).
    expect($labels[0])->toBe('Dashboard')
        ->and($labels)->toContain('Users')
        ->and($labels)->toContain('Companies')
        ->and($labels)->toContain('Activity log');
});

it('lets an owner see all tenant menu items via owner bypass', function () {
    $owner = navUser('owner@x.test');
    $company = navCompanyOwnedBy($owner);
    navActivate($owner, $company);

    $menu = app(MenuBuilder::class)->build('tenant', $owner);
    $labels = array_column($menu, 'labelKey');

    expect($labels)->toContain('Employees')
        ->and($labels)->toContain('Roles');
});

it('hides tenant items a member lacks permission for', function () {
    $owner = navUser('owner2@x.test');
    $company = navCompanyOwnedBy($owner);
    $member = navUser('member@x.test');
    $company->addEmployee($member, addedById: $owner->id);
    navActivate($member, $company);

    $menu = app(MenuBuilder::class)->build('tenant', $member);

    // A bare member has no company.employees.view / company.roles.view, so the
    // core tenant items are filtered out.
    expect(array_column($menu, 'labelKey'))->toBe([]);
});

it('checks a permission slug through the RBAC trait', function () {
    $owner = navUser('owner3@x.test');
    $company = navCompanyOwnedBy($owner);
    navActivate($owner, $company);

    $checker = app(RbacPolicyChecker::class);

    // Owner bypass grants any slug in their active company.
    expect($checker->check($owner, 'company.roles.view'))->toBeTrue();
});

it('super-admin sees admin navigation via shared props', function () {
    $admin = navAdmin('root@x.test');
    $this->actingAs($admin);

    $props = LaraFoundryNavigation::sharedProps();

    expect($props['visitor_status']())->toBe('admin')
        ->and(array_column($props['navigation'](), 'labelKey'))->toContain('Users');
});

it('a guest gets an empty navigation and guest status', function () {
    $props = LaraFoundryNavigation::sharedProps();

    expect($props['navigation']())->toBe([])
        ->and($props['visitor_status']())->toBe('guest')
        ->and($props['nav_level']())->toBeNull();
});

it('ships nav_level so the shell keys off the same signal as the menu', function () {
    $admin = navAdmin('navlevel@x.test');
    $this->actingAs($admin);

    // nav_level is the single source of truth the LayoutSwitcher uses; it must
    // match the level that built the menu (admin here).
    expect(LaraFoundryNavigation::sharedProps()['nav_level']())->toBe('admin');
});

it('reports not impersonating by default', function () {
    $this->actingAs(navAdmin('root2@x.test'));

    $props = LaraFoundryNavigation::sharedProps();

    expect($props['impersonating']())->toBeFalse();
});
