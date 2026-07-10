<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Profile\Models\UserSocialLink;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Phase 3b — user forms: demographic persist, social links, url XSS guard
|--------------------------------------------------------------------------
| Shares the admin-console harness with AdminUsersTest (auAdmin/auMember are
| defined there and autoloaded by Pest across the same directory).
*/

beforeEach(function () {
    config(['larafoundry-activitylog.geo.enabled' => false]);
    config(['inertia.testing.ensure_pages_exist' => false]);
    config(['larafoundry.security.super_admin.require_otp' => false]);
});

it('exposes the socialLinks relation ordered by sort', function () {
    $user = auMember('rel@x.test');

    $user->socialLinks()->create(['platform' => 'github', 'url' => 'https://github.com/b', 'sort' => 1]);
    $user->socialLinks()->create(['platform' => 'website', 'url' => 'https://a.test', 'sort' => 0]);

    expect($user->socialLinks()->pluck('platform')->all())->toBe(['website', 'github']);
});

it('persists demographic fields and social links on create', function () {
    $this->actingAs(auAdmin())
        ->post('/admin/users', [
            'name' => 'New',
            'middlename' => 'Mid',
            'email' => 'new@x.test',
            'sex' => 'm',
            'birth_date' => '1990-05-04',
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
            'social_links' => [
                ['platform' => 'github', 'url' => 'https://github.com/new'],
                ['platform' => 'website', 'url' => 'https://new.test'],
            ],
        ])
        ->assertRedirect();

    // Full payload persisted (not just the redirect).
    $this->assertDatabaseHas('users', [
        'email' => 'new@x.test',
        'middlename' => 'Mid',
        'sex' => 'm',
    ]);

    $user = User::where('email', 'new@x.test')->first();

    // birth_date round-trips through the `date:Y-m-d` cast (the DB stores it with
    // a time component, so assert the cast-aware value rather than the raw cell).
    expect($user->birth_date->format('Y-m-d'))->toBe('1990-05-04');

    $this->assertDatabaseHas('larafoundry_user_social_links', [
        'user_id' => $user->id,
        'platform' => 'github',
        'url' => 'https://github.com/new',
        'sort' => 0,
    ]);
    $this->assertDatabaseHas('larafoundry_user_social_links', [
        'user_id' => $user->id,
        'platform' => 'website',
        'url' => 'https://new.test',
        'sort' => 1,
    ]);
});

it('replaces social links on update', function () {
    $admin = auAdmin();
    $target = auMember('repl@x.test');
    $target->socialLinks()->create(['platform' => 'twitter', 'url' => 'https://x.com/old', 'sort' => 0]);

    $this->actingAs($admin)
        ->put("/admin/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'social_links' => [
                ['platform' => 'linkedin', 'url' => 'https://linkedin.com/in/new'],
            ],
        ])
        ->assertRedirect();

    expect(UserSocialLink::where('user_id', $target->id)->pluck('platform')->all())
        ->toBe(['linkedin']);

    $this->assertDatabaseHas('larafoundry_user_social_links', [
        'user_id' => $target->id,
        'platform' => 'linkedin',
        'url' => 'https://linkedin.com/in/new',
        'sort' => 0,
    ]);
    $this->assertDatabaseMissing('larafoundry_user_social_links', [
        'user_id' => $target->id,
        'platform' => 'twitter',
    ]);
});

it('clears social links on an explicit empty array', function () {
    $admin = auAdmin();
    $target = auMember('clear@x.test');
    $target->socialLinks()->create(['platform' => 'github', 'url' => 'https://github.com/x', 'sort' => 0]);

    $this->actingAs($admin)
        ->put("/admin/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'social_links' => [],
        ])
        ->assertRedirect();

    expect(UserSocialLink::where('user_id', $target->id)->count())->toBe(0);
});

it('does NOT touch social links when the key is absent from the request (gated-safe)', function () {
    // Regression guard: a disabled `social` token means the form never submits
    // `social_links`, so an update MUST leave the stored links intact (the same
    // class of bug as the phase-3a gated-field-wipe HIGH).
    $admin = auAdmin();
    $target = auMember('safe@x.test');
    $target->socialLinks()->create(['platform' => 'github', 'url' => 'https://github.com/keep', 'sort' => 0]);

    $this->actingAs($admin)
        ->put("/admin/users/{$target->id}", [
            'name' => 'Renamed',
            'email' => $target->email,
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('larafoundry_user_social_links', [
        'user_id' => $target->id,
        'platform' => 'github',
        'url' => 'https://github.com/keep',
    ]);
    expect(UserSocialLink::where('user_id', $target->id)->count())->toBe(1);
});

it('never touches another user\'s links when syncing', function () {
    $admin = auAdmin();
    $target = auMember('me@x.test');
    $other = auMember('other@x.test');
    $other->socialLinks()->create(['platform' => 'website', 'url' => 'https://other.test', 'sort' => 0]);

    $this->actingAs($admin)
        ->put("/admin/users/{$target->id}", [
            'name' => $target->name,
            'email' => $target->email,
            'social_links' => [
                ['platform' => 'github', 'url' => 'https://github.com/me'],
            ],
        ])
        ->assertRedirect();

    // The other user's link is untouched.
    $this->assertDatabaseHas('larafoundry_user_social_links', [
        'user_id' => $other->id,
        'platform' => 'website',
        'url' => 'https://other.test',
    ]);
});

it('keeps the same social link rows when the submitted set is unchanged', function () {
    $admin = auAdmin();
    $target = auMember('nochurn@x.test');
    $link = $target->socialLinks()->create(['platform' => 'github', 'url' => 'https://github.com/keep', 'sort' => 0]);

    // The edit form always resubmits the prefilled links; an unchanged set must
    // be a no-op (no delete+recreate), so the row id/timestamps do not churn.
    $this->actingAs($admin)
        ->put("/admin/users/{$target->id}", [
            'name' => 'Renamed',
            'email' => $target->email,
            'social_links' => [
                ['platform' => 'github', 'url' => 'https://github.com/keep'],
            ],
        ])
        ->assertRedirect();

    $rows = UserSocialLink::where('user_id', $target->id)->get();
    expect($rows)->toHaveCount(1)
        ->and($rows->first()->id)->toBe($link->id);
});

it('rejects a javascript: scheme in a social url (stored-XSS guard)', function () {
    $this->actingAs(auAdmin())
        ->post('/admin/users', [
            'name' => 'Bad',
            'email' => 'bad@x.test',
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
            'social_links' => [
                ['platform' => 'website', 'url' => 'javascript:alert(1)'],
            ],
        ])
        ->assertSessionHasErrors('social_links.0.url');

    expect(User::where('email', 'bad@x.test')->exists())->toBeFalse();
});

it('rejects a data: scheme in a social url', function () {
    $this->actingAs(auAdmin())
        ->post('/admin/users', [
            'name' => 'Bad',
            'email' => 'bad2@x.test',
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
            'social_links' => [
                ['platform' => 'website', 'url' => 'data:text/html,<script>alert(1)</script>'],
            ],
        ])
        ->assertSessionHasErrors('social_links.0.url');
});

it('rejects a platform outside the whitelist', function () {
    $this->actingAs(auAdmin())
        ->post('/admin/users', [
            'name' => 'Bad',
            'email' => 'bad3@x.test',
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
            'social_links' => [
                ['platform' => 'myspace', 'url' => 'https://myspace.com/x'],
            ],
        ])
        ->assertSessionHasErrors('social_links.0.platform');
});

it('requires the password confirmation to match on create', function () {
    $this->actingAs(auAdmin())
        ->post('/admin/users', [
            'name' => 'Mismatch',
            'email' => 'mismatch@x.test',
            'password' => 'secret-pass',
            'password_confirmation' => 'different-pass',
        ])
        ->assertSessionHasErrors('password');

    expect(User::where('email', 'mismatch@x.test')->exists())->toBeFalse();
});

it('rejects a sex value longer than one character', function () {
    $this->actingAs(auAdmin())
        ->post('/admin/users', [
            'name' => 'LongSex',
            'email' => 'longsex@x.test',
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
            'sex' => 'male',
        ])
        ->assertSessionHasErrors('sex');
});

it('rejects a non-date birth_date', function () {
    $this->actingAs(auAdmin())
        ->post('/admin/users', [
            'name' => 'BadDob',
            'email' => 'baddob@x.test',
            'password' => 'secret-pass',
            'password_confirmation' => 'secret-pass',
            'birth_date' => 'not-a-date',
        ])
        ->assertSessionHasErrors('birth_date');
});

it('serialises social_links only when the social token is opted in', function () {
    $admin = auAdmin();
    $u = auMember('slist@x.test');
    $u->socialLinks()->create(['platform' => 'github', 'url' => 'https://github.com/s', 'sort' => 0]);

    // Default config: no social column -> not serialised.
    config(['larafoundry.admin.user_columns' => []]);
    $first = collect(
        $this->actingAs($admin)
            ->get('/admin/users', ['X-Inertia' => 'true'])
            ->json('props.users.data')
    )->firstWhere('email', 'slist@x.test');
    expect($first)->not->toHaveKey('social_links');

    // Opted in -> present with platform/url only.
    config(['larafoundry.admin.user_columns' => ['social']]);
    $row = collect(
        $this->actingAs($admin)
            ->get('/admin/users', ['X-Inertia' => 'true'])
            ->json('props.users.data')
    )->firstWhere('email', 'slist@x.test');

    expect($row)->toHaveKey('social_links')
        ->and($row['social_links'])->toBe([
            ['platform' => 'github', 'url' => 'https://github.com/s'],
        ]);
});

it('always ships social_links to the edit form regardless of config', function () {
    config(['larafoundry.admin.user_columns' => []]);
    $admin = auAdmin();
    $target = auMember('sedit@x.test');
    $target->socialLinks()->create(['platform' => 'website', 'url' => 'https://s.test', 'sort' => 0]);

    $user = $this->actingAs($admin)
        ->get("/admin/users/{$target->id}/edit", ['X-Inertia' => 'true'])
        ->assertOk()
        ->json('props.user');

    $data = $user['data'] ?? $user;

    expect($data['social_links'])->toBe([
        ['platform' => 'website', 'url' => 'https://s.test'],
    ]);
    // The edit view also carries the raw birth_date + middlename for prefill.
    expect($data)->toHaveKey('birth_date')
        ->and($data)->toHaveKey('middlename');
});
