<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * Register runtime routes behind `larafoundry.confine_admin` so the route-name
 * allow-list (tenant route vs. console/pin/admin routes) is exercised through
 * the real router, the same way the host wires it on its web group.
 */
beforeEach(function () {
    config()->set('larafoundry.security.super_admin.email', null);

    Route::middleware(['web', 'larafoundry.confine_admin'])->group(function () {
        Route::get('/tenant-area', fn () => 'tenant')->name('tenant.area');
        Route::get('/pin/enter', fn () => 'pin')->name('pin.enter');
        Route::get('/admin/section', fn () => 'console')->name('admin.section');
    });
    Route::getRoutes()->refreshNameLookups();
});

function confinementUser(bool $admin): User
{
    return User::create([
        'name' => $admin ? 'Boss' : 'Member',
        'email' => $admin ? 'boss@x.test' : 'member@x.test',
        'password' => 'secret-pass',
        'is_admin' => $admin,
    ]);
}

it('redirects a super-admin from a tenant route into the console', function () {
    $this->actingAs(confinementUser(admin: true))
        ->get('/tenant-area')
        ->assertRedirect(route('admin.dashboard.index'));
});

it('lets a super-admin reach an allow-listed admin route', function () {
    $this->actingAs(confinementUser(admin: true))
        ->get('/admin/section')
        ->assertOk()
        ->assertSee('console');
});

it('lets a super-admin reach the pin screens', function () {
    $this->actingAs(confinementUser(admin: true))
        ->get('/pin/enter')
        ->assertOk()
        ->assertSee('pin');
});

it('leaves a normal user untouched on tenant routes', function () {
    $this->actingAs(confinementUser(admin: false))
        ->get('/tenant-area')
        ->assertOk()
        ->assertSee('tenant');
});

it('leaves a guest untouched', function () {
    $this->get('/tenant-area')
        ->assertOk()
        ->assertSee('tenant');
});

it('answers a JSON request with 403 instead of redirecting', function () {
    $this->actingAs(confinementUser(admin: true))
        ->getJson('/tenant-area')
        ->assertForbidden();
});

it('still redirects an Inertia request (it follows the 302)', function () {
    $this->actingAs(confinementUser(admin: true))
        ->withHeader('X-Inertia', 'true')
        ->get('/tenant-area')
        ->assertRedirect(route('admin.dashboard.index'));
});
