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

        // Mirrors an operator tool that gates itself: Telescope names only its SPA
        // shell, and every data call it makes is an UNNAMED route.
        Route::get('/telescope/{view?}', fn () => 'telescope shell')->where('view', '(.*)')->name('telescope');
        Route::post('/telescope/telescope-api/commands', fn () => 'telescope data');

        Route::get('/', fn () => 'landing')->name('landing');
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

it('names the reason in the 403 body so a refused XHR does not read as a broken tool', function () {
    $this->actingAs(confinementUser(admin: true))
        ->getJson('/tenant-area')
        ->assertForbidden()
        ->assertJsonPath('error', 'super_admin_confined')
        ->assertJsonStructure(['error', 'message']);
});

it('still redirects an Inertia request (it follows the 302)', function () {
    $this->actingAs(confinementUser(admin: true))
        ->withHeader('X-Inertia', 'true')
        ->get('/tenant-area')
        ->assertRedirect(route('admin.dashboard.index'));
});

/*
 * allowed_paths — the escape hatch for operator tooling whose routes carry no name
 * (Telescope's data endpoints, log-viewer's, Horizon's). Default is an empty array,
 * so a host that never sets it keeps the exact behaviour it had before the key.
 */

it('confines the operator out of an unnamed package route while allowed_paths is empty', function () {
    expect(config('larafoundry.security.super_admin.allowed_paths'))->toBe([]);

    $operator = confinementUser(admin: true);

    $this->actingAs($operator)
        ->postJson('/telescope/telescope-api/commands')
        ->assertForbidden();

    $this->actingAs($operator)
        ->get('/telescope')
        ->assertRedirect(route('admin.dashboard.index'));
});

it('lets the operator through an allow-listed path, named shell and unnamed data route alike', function () {
    config()->set('larafoundry.security.super_admin.allowed_paths', ['telescope', 'telescope/*']);

    $operator = confinementUser(admin: true);

    $this->actingAs($operator)
        ->get('/telescope')
        ->assertOk()
        ->assertSee('telescope shell');

    $this->actingAs($operator)
        ->postJson('/telescope/telescope-api/commands')
        ->assertOk();
});

it('keeps confining tenant routes while a path is allow-listed', function () {
    config()->set('larafoundry.security.super_admin.allowed_paths', ['telescope', 'telescope/*']);

    $operator = confinementUser(admin: true);

    $this->actingAs($operator)
        ->get('/tenant-area')
        ->assertRedirect(route('admin.dashboard.index'));

    $this->actingAs($operator)
        ->getJson('/tenant-area')
        ->assertForbidden();
});

it('treats a leading slash in a pattern as the same pattern', function () {
    config()->set('larafoundry.security.super_admin.allowed_paths', ['/telescope']);

    $this->actingAs(confinementUser(admin: true))
        ->get('/telescope')
        ->assertOk();
});

it('does not let a wildcard-only pattern open the bare path', function () {
    config()->set('larafoundry.security.super_admin.allowed_paths', ['telescope/*']);

    $operator = confinementUser(admin: true);

    $this->actingAs($operator)
        ->get('/telescope')
        ->assertRedirect(route('admin.dashboard.index'));

    $this->actingAs($operator)
        ->postJson('/telescope/telescope-api/commands')
        ->assertOk();
});

it('drops an empty pattern rather than letting it decide anything', function () {
    // The root is where an empty pattern would show: Request::is('') matches nothing,
    // but a pattern that survived trimming to '' must not be consulted at all.
    config()->set('larafoundry.security.super_admin.allowed_paths', ['']);

    $this->actingAs(confinementUser(admin: true))
        ->get('/')
        ->assertRedirect(route('admin.dashboard.index'));
});

it('keeps a bare slash meaning the root path', function () {
    config()->set('larafoundry.security.super_admin.allowed_paths', ['/']);

    $operator = confinementUser(admin: true);

    $this->actingAs($operator)
        ->get('/')
        ->assertOk()
        ->assertSee('landing');

    // ...and only the root — it is a path, not a prefix.
    $this->actingAs($operator)
        ->get('/tenant-area')
        ->assertRedirect(route('admin.dashboard.index'));
});

it('refuses to let a bare wildcard switch the confinement off wholesale', function () {
    config()->set('larafoundry.security.super_admin.allowed_paths', ['*']);

    $this->actingAs(confinementUser(admin: true))
        ->get('/tenant-area')
        ->assertRedirect(route('admin.dashboard.index'));
});

it('skips non-string entries instead of erroring on them', function () {
    config()->set('larafoundry.security.super_admin.allowed_paths', [['telescope'], 42, null, 'telescope']);

    $operator = confinementUser(admin: true);

    $this->actingAs($operator)
        ->get('/telescope')
        ->assertOk();

    $this->actingAs($operator)
        ->get('/tenant-area')
        ->assertRedirect(route('admin.dashboard.index'));
});

it('accepts a single pattern given as a bare string', function () {
    config()->set('larafoundry.security.super_admin.allowed_paths', 'telescope');

    $this->actingAs(confinementUser(admin: true))
        ->get('/telescope')
        ->assertOk();
});

it('leaves a normal user untouched on an allow-listed path', function () {
    config()->set('larafoundry.security.super_admin.allowed_paths', ['telescope', 'telescope/*']);

    $this->actingAs(confinementUser(admin: false))
        ->get('/telescope')
        ->assertOk()
        ->assertSee('telescope shell');
});
