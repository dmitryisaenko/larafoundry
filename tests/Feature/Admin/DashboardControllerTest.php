<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['larafoundry-activitylog.geo.enabled' => false]);
    config(['inertia.testing.ensure_pages_exist' => false]);
});

function dashAdmin(string $email = 'dashboss@x.test'): User
{
    return User::create([
        'name' => 'Boss',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);
}

function dashUser(string $email = 'dashjoe@x.test'): User
{
    return User::create([
        'name' => 'Joe',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

it('forbids a non-admin from the dashboard', function () {
    $this->actingAs(dashUser())->get('/admin')->assertForbidden();
});

it('redirects a guest from the dashboard', function () {
    $this->get('/admin')->assertRedirect();
});

it('renders the dashboard for a super-admin', function () {
    $this->actingAs(dashAdmin())
        ->get('/admin', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Admin/Dashboard');
});

it('ships the three FREE core widgets in order with their components and data', function () {
    $widgets = $this->actingAs(dashAdmin())
        ->get('/admin', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.widgets');

    expect(array_column($widgets, 'key'))->toBe(['core.users', 'core.companies', 'core.activity'])
        ->and($widgets[0]['component'])->toBe('UsersWidget')
        ->and($widgets[0]['titleKey'])->toBe('Users')
        // The acting super-admin is itself a user, so the count is real.
        ->and($widgets[0]['data']['total'])->toBeGreaterThanOrEqual(1)
        ->and($widgets[1]['component'])->toBe('CompaniesWidget')
        ->and($widgets[2]['component'])->toBe('ActivityWidget');
});

it('exposes no revenue widget in the FREE core (that is the billing add-on)', function () {
    $widgets = $this->actingAs(dashAdmin())
        ->get('/admin', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.widgets');

    expect(array_column($widgets, 'key'))->not->toContain('billing.revenue');
});

it('ships title keys, not pre-translated strings', function () {
    $widgets = $this->actingAs(dashAdmin())
        ->get('/admin', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.widgets');

    // English-as-key: the backend never translates; Vue does, at render.
    expect($widgets[0]['titleKey'])->toBe('Users');
});
