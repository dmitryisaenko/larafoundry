<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Admin\Http\Resources\AdminUserResource;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

/*
 * Host seam (phase 7): a host adds extra user-list columns without forking the
 * Vue table by subclassing AdminUserResource, overriding extra(), and pointing
 * `larafoundry.admin.user_resource` at the subclass. The controller resolves the
 * class, validates it, and the resource emits the cells under `extra_columns`.
 */

class SeamAdminUserResource extends AdminUserResource
{
    protected function extra(Request $request): array
    {
        return [
            ['key' => 'demo', 'label' => 'Demo', 'value' => 'Yes', 'badge' => 'emerald'],
        ];
    }
}

beforeEach(function () {
    config(['larafoundry-activitylog.geo.enabled' => false]);
    config(['inertia.testing.ensure_pages_exist' => false]);
    config(['larafoundry.security.super_admin.require_otp' => false]);
});

function seamAdmin(): User
{
    return User::create([
        'name' => 'Boss', 'email' => 'seam-boss@x.test', 'password' => 'secret-pass',
        'email_verified_at' => now(), 'is_admin' => true,
    ]);
}

it('renders host extra columns when the resource is swapped via config', function () {
    config(['larafoundry.admin.user_resource' => SeamAdminUserResource::class]);
    $admin = seamAdmin();

    $first = $this->actingAs($admin)
        ->get('/admin/users', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.users.data.0');

    expect($first['extra_columns'])->toHaveCount(1)
        ->and($first['extra_columns'][0])->toMatchArray([
            'key' => 'demo',
            'label' => 'Demo',
            'value' => 'Yes',
            'badge' => 'emerald',
        ]);
});

it('ignores a config class that does not extend AdminUserResource (falls back to core)', function () {
    config(['larafoundry.admin.user_resource' => stdClass::class]);
    $admin = seamAdmin();

    $first = $this->actingAs($admin)
        ->get('/admin/users', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.users.data.0');

    // The bogus class is rejected; the core resource is used, so no host cells.
    expect($first['extra_columns'])->toBe([]);
});
