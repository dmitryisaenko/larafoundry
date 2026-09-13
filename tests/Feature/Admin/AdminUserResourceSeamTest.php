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

/**
 * The second cell form: a set of clickable badges instead of text. The table
 * renders one control per badge and emits the badge key upwards; the resource's
 * only job is to carry the description through intact.
 */
class BadgeSeamAdminUserResource extends AdminUserResource
{
    protected function extra(Request $request): array
    {
        return [
            [
                'key' => 'access',
                'label' => 'Access',
                'items' => [
                    ['key' => 'texts', 'text' => 'T', 'tone' => 'muted', 'title' => 'AI texts: as for everyone (on)'],
                    ['key' => 'search', 'icon' => 'search', 'tone' => 'off', 'title' => 'YouTube search: blocked'],
                    ['key' => 'paid', 'text' => '$', 'tone' => 'on', 'clickable' => false],
                ],
            ],
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
    // A listed (non-operator) user: the operator itself is hidden from the list.
    User::create(['name' => 'Joe', 'email' => 'joe@x.test', 'password' => 'secret-pass', 'email_verified_at' => now()]);

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

it('carries a badge-set cell through to the page props intact', function () {
    config(['larafoundry.admin.user_resource' => BadgeSeamAdminUserResource::class]);
    $admin = seamAdmin();
    // A listed (non-operator) user: the operator itself is hidden from the list.
    User::create(['name' => 'Joe', 'email' => 'joe@x.test', 'password' => 'secret-pass', 'email_verified_at' => now()]);

    $first = $this->actingAs($admin)
        ->get('/admin/users', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.users.data.0');

    expect($first['extra_columns'])->toHaveCount(1);

    $cell = $first['extra_columns'][0];

    expect($cell['key'])->toBe('access')
        ->and($cell['label'])->toBe('Access')
        // The text form's keys are absent: a cell is one form or the other.
        ->and($cell)->not->toHaveKey('value')
        ->and($cell['items'])->toHaveCount(3)
        // Every badge arrives whole — key, tone, caption/glyph and the inert flag.
        ->and(array_column($cell['items'], 'key'))->toBe(['texts', 'search', 'paid'])
        ->and(array_column($cell['items'], 'tone'))->toBe(['muted', 'off', 'on'])
        ->and($cell['items'][0])->toMatchArray([
            'key' => 'texts',
            'text' => 'T',
            'tone' => 'muted',
            'title' => 'AI texts: as for everyone (on)',
        ])
        ->and($cell['items'][1])->toMatchArray(['key' => 'search', 'icon' => 'search', 'tone' => 'off'])
        ->and($cell['items'][2])->toMatchArray(['key' => 'paid', 'text' => '$', 'clickable' => false]);
});

it('ignores a config class that does not extend AdminUserResource (falls back to core)', function () {
    config(['larafoundry.admin.user_resource' => stdClass::class]);
    $admin = seamAdmin();
    // A listed (non-operator) user: the operator itself is hidden from the list.
    User::create(['name' => 'Joe', 'email' => 'joe@x.test', 'password' => 'secret-pass', 'email_verified_at' => now()]);

    $first = $this->actingAs($admin)
        ->get('/admin/users', ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.users.data.0');

    // The bogus class is rejected; the core resource is used, so no host cells.
    expect($first['extra_columns'])->toBe([]);
});
