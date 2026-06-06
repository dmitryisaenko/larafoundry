<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;

/**
 * Build an in-memory user with the given identity attributes (no DB needed:
 * VisitorStatus reads through the trait's predicate methods / getAttribute).
 */
function statusUser(array $attributes): User
{
    $user = new User;
    $user->forceFill($attributes);

    return $user;
}

it('reports guest when no user is present', function () {
    expect((new VisitorStatus)->for(null))->toBe(VisitorStatus::GUEST);
});

it('reports authenticated for an unverified plain user', function () {
    $user = statusUser(['email' => 'u@x.test', 'is_admin' => false]);

    expect((new VisitorStatus)->for($user))->toBe(VisitorStatus::AUTHENTICATED);
});

it('reports verified when the email is verified', function () {
    $user = statusUser([
        'email' => 'u@x.test',
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    expect((new VisitorStatus)->for($user))->toBe(VisitorStatus::VERIFIED);
});

it('reports blocked over verified', function () {
    $user = statusUser([
        'email' => 'u@x.test',
        'email_verified_at' => now(),
        'user_blocked_at' => now(),
    ]);

    expect((new VisitorStatus)->for($user))->toBe(VisitorStatus::BLOCKED);
});

it('reports deleted over everything else', function () {
    $user = statusUser([
        'email' => 'u@x.test',
        'email_verified_at' => now(),
        'user_blocked_at' => now(),
        'user_deleted_at' => now(),
        'is_admin' => true,
    ]);

    expect((new VisitorStatus)->for($user))->toBe(VisitorStatus::DELETED);
});

it('reports admin for a flagged user when no allow-list is set', function () {
    config()->set('larafoundry.auth.failed_login.admin_email', null);
    $user = statusUser(['email' => 'boss@x.test', 'is_admin' => true]);

    expect((new VisitorStatus)->for($user))->toBe(VisitorStatus::ADMIN);
});

it('grants admin when the flagged user matches the allow-list email', function () {
    config()->set('larafoundry.auth.failed_login.admin_email', 'boss@x.test');
    $user = statusUser(['email' => 'boss@x.test', 'is_admin' => true]);

    expect((new VisitorStatus)->isAdmin($user))->toBeTrue();
    expect((new VisitorStatus)->for($user))->toBe(VisitorStatus::ADMIN);
});

it('denies admin when the flagged user does not match the allow-list email', function () {
    config()->set('larafoundry.auth.failed_login.admin_email', 'boss@x.test');
    $user = statusUser(['email' => 'someone@x.test', 'is_admin' => true]);

    expect((new VisitorStatus)->isAdmin($user))->toBeFalse();
    // Falls through to authenticated (flag alone is not enough).
    expect((new VisitorStatus)->for($user))->toBe(VisitorStatus::AUTHENTICATED);
});

it('never reports admin for a non-flagged user', function () {
    $user = statusUser(['email' => 'u@x.test', 'is_admin' => false]);

    expect((new VisitorStatus)->isAdmin($user))->toBeFalse();
});

it('grants admin via the canonical super_admin.email key', function () {
    config()->set('larafoundry.security.super_admin.email', 'boss@x.test');
    config()->set('larafoundry.auth.failed_login.admin_email', null);
    $user = statusUser(['email' => 'boss@x.test', 'is_admin' => true]);

    expect((new VisitorStatus)->isAdmin($user))->toBeTrue();
});

it('denies admin when the flagged user does not match super_admin.email', function () {
    config()->set('larafoundry.security.super_admin.email', 'boss@x.test');
    $user = statusUser(['email' => 'intruder@x.test', 'is_admin' => true]);

    expect((new VisitorStatus)->isAdmin($user))->toBeFalse();
});

it('prefers super_admin.email over the legacy allow-list key', function () {
    config()->set('larafoundry.security.super_admin.email', 'boss@x.test');
    config()->set('larafoundry.auth.failed_login.admin_email', 'legacy@x.test');

    expect(VisitorStatus::superAdminEmail())->toBe('boss@x.test');
});

it('falls back to the legacy allow-list key when super_admin.email is empty', function () {
    config()->set('larafoundry.security.super_admin.email', null);
    config()->set('larafoundry.auth.failed_login.admin_email', 'legacy@x.test');

    expect(VisitorStatus::superAdminEmail())->toBe('legacy@x.test');
});

it('resolves no super-admin email when neither key is set', function () {
    config()->set('larafoundry.security.super_admin.email', null);
    config()->set('larafoundry.auth.failed_login.admin_email', null);

    expect(VisitorStatus::superAdminEmail())->toBeNull();
});

it('matches the reserved email case-insensitively', function () {
    config()->set('larafoundry.security.super_admin.email', 'Boss@x.test');

    expect(VisitorStatus::isSuperAdminEmail('boss@x.test'))->toBeTrue()
        ->and(VisitorStatus::isSuperAdminEmail('BOSS@X.TEST'))->toBeTrue()
        ->and(VisitorStatus::isSuperAdminEmail('other@x.test'))->toBeFalse()
        ->and(VisitorStatus::isSuperAdminEmail(null))->toBeFalse();
});

it('reports no reserved email match when none is configured', function () {
    config()->set('larafoundry.security.super_admin.email', null);
    config()->set('larafoundry.auth.failed_login.admin_email', null);

    expect(VisitorStatus::isSuperAdminEmail('anyone@x.test'))->toBeFalse();
});

it('grants admin to the operator regardless of email case', function () {
    config()->set('larafoundry.security.super_admin.email', 'boss@x.test');
    $user = statusUser(['email' => 'BOSS@X.TEST', 'is_admin' => true]);

    expect((new VisitorStatus)->isAdmin($user))->toBeTrue();
});
