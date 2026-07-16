<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Http\Middleware\EnsureAdminOtpVerified;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

// Flag-based super-admin resolution (no exclusive email) keeps these focused on
// the security surface, not identity resolution.
beforeEach(function () {
    config()->set('larafoundry.security.super_admin.email', null);
    config()->set('inertia.testing.ensure_pages_exist', false);
});

/**
 * A verified super-admin. `$secret` (when 2FA is requested) is returned decrypted
 * so a test can compute a live TOTP code; `confirmed` marks enrolment complete.
 */
function securityAdmin(bool $with2fa = false, bool $confirmed = false): array
{
    $user = User::create([
        'name' => 'Boss',
        'email' => 'boss@x.test',
        'password' => 'secret-pass',
        'is_admin' => true,
        'email_verified_at' => now(),
    ]);

    $secret = null;

    if ($with2fa) {
        $secret = (new Google2FA)->generateSecretKey();
        $user->forceFill([
            'two_factor_secret' => Crypt::encrypt($secret),
            'two_factor_recovery_codes' => Crypt::encrypt(json_encode(['ZZZZZ-AAAAA', 'YYYYY-BBBBB'])),
            'two_factor_confirmed_at' => $confirmed ? now() : null,
        ])->save();
    }

    return [$user, $secret];
}

// --- Access: closes the chicken-and-egg -----------------------------------

it('lets an un-enrolled operator reach the security page (no OTP-gate loop)', function () {
    config()->set('larafoundry.security.super_admin.require_otp', true);
    [$admin] = securityAdmin(with2fa: false);

    $this->actingAs($admin)
        ->get('/admin/security', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Admin/Security/Index');
});

it('forbids a non-admin from the operator security page', function () {
    $user = User::create([
        'name' => 'Member',
        'email' => 'member@x.test',
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)->get('/admin/security')->assertForbidden();
});

it('redirects an un-enrolled operator to the in-console security page by default', function () {
    // No override of two_factor_setup_route → the config default (admin.security.show)
    // applies, so the OTP gate sends an un-enrolled operator there to enrol.
    config()->set('larafoundry.security.super_admin.require_otp', true);
    [$admin] = securityAdmin(with2fa: false);

    $this->actingAs($admin)
        ->get('/admin/users', ['X-Inertia' => 'true'])
        ->assertRedirect(route('admin.security.show'));
});

// --- Two-factor enrolment --------------------------------------------------

it('enables two-factor for the operator', function () {
    [$admin] = securityAdmin(with2fa: false);

    $this->actingAs($admin)
        ->post('/admin/security/two-factor/enable')
        ->assertRedirect();

    expect($admin->fresh()->two_factor_secret)->not->toBeNull();
});

it('confirms enrolment with a valid TOTP code', function () {
    [$admin, $secret] = securityAdmin(with2fa: true, confirmed: false);

    $code = (new Google2FA)->getCurrentOtp($secret);

    $this->actingAs($admin)
        ->post('/admin/security/two-factor/confirm', ['code' => $code])
        ->assertRedirect();

    expect($admin->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

it('rejects an invalid confirmation code (into the confirm error bag)', function () {
    [$admin] = securityAdmin(with2fa: true, confirmed: false);

    $this->actingAs($admin)
        ->from('/admin/security')
        ->post('/admin/security/two-factor/confirm', ['code' => '000000'])
        ->assertRedirect('/admin/security')
        ->assertSessionHasErrors('code', null, 'confirmTwoFactorAuthentication');

    expect($admin->fresh()->two_factor_confirmed_at)->toBeNull();
});

it('disables two-factor for a stepped-up operator', function () {
    [$admin] = securityAdmin(with2fa: true, confirmed: true);

    $this->actingAs($admin)
        ->withSession([EnsureAdminOtpVerified::SESSION_KEY => true])
        ->delete('/admin/security/two-factor')
        ->assertRedirect();

    expect($admin->fresh()->two_factor_secret)->toBeNull();
});

it('will not disable two-factor without a fresh OTP step-up', function () {
    config()->set('larafoundry.security.super_admin.require_otp', true);
    config()->set('inertia.testing.ensure_pages_exist', false);
    [$admin] = securityAdmin(with2fa: true, confirmed: true);

    // No step-up flag in the session → the destructive route is gated.
    $this->actingAs($admin)
        ->delete('/admin/security/two-factor', ['X-Inertia' => 'true'])
        ->assertRedirect(route('admin.otp.show'));

    expect($admin->fresh()->two_factor_secret)->not->toBeNull();
});

it('regenerates recovery codes for a stepped-up operator', function () {
    [$admin] = securityAdmin(with2fa: true, confirmed: true);
    $before = $admin->recoveryCodes();

    $this->actingAs($admin)
        ->withSession([EnsureAdminOtpVerified::SESSION_KEY => true])
        ->post('/admin/security/two-factor/recovery-codes')
        ->assertRedirect();

    expect($admin->fresh()->recoveryCodes())->not->toEqual($before);
});

// --- Secret exposure is gated to enrolment / stepped-up sessions -----------

it('exposes the enrolment QR + recovery codes only while enrolment is unconfirmed', function () {
    [$admin] = securityAdmin(with2fa: true, confirmed: false);

    $this->actingAs($admin)
        ->get('/admin/security', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.two_factor_setup.svg', fn ($svg) => is_string($svg) && $svg !== '')
        ->assertJsonPath('props.two_factor_setup.recovery_codes', fn ($codes) => is_array($codes) && count($codes) > 0);
});

it('hides recovery codes from a confirmed operator who has not stepped up', function () {
    [$admin] = securityAdmin(with2fa: true, confirmed: true);

    // Confirmed 2FA but NO step-up flag: neither the enrolment payload nor the
    // review codes are shipped, and destructive actions are hidden.
    $this->actingAs($admin)
        ->get('/admin/security', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.two_factor_setup', null)
        ->assertJsonPath('props.recovery_codes', null)
        ->assertJsonPath('props.can_manage_two_factor', false);
});

it('ships recovery codes for review to a stepped-up operator', function () {
    [$admin] = securityAdmin(with2fa: true, confirmed: true);

    $this->actingAs($admin)
        ->withSession([EnsureAdminOtpVerified::SESSION_KEY => true])
        ->get('/admin/security', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('props.recovery_codes', fn ($codes) => is_array($codes) && count($codes) > 0)
        ->assertJsonPath('props.can_manage_two_factor', true);
});

// --- Password change -------------------------------------------------------

it('rejects a password change with the wrong current password', function () {
    [$admin] = securityAdmin();

    $this->actingAs($admin)
        ->from('/admin/security')
        ->put('/admin/security/password', [
            'current_password' => 'wrong-password',
            'password' => 'New-Str0ng-Passw0rd!',
            'password_confirmation' => 'New-Str0ng-Passw0rd!',
        ])
        ->assertRedirect('/admin/security')
        ->assertSessionHasErrors('current_password', null, 'updatePassword');
});

it('changes the password with the correct current password', function () {
    [$admin] = securityAdmin();

    $this->actingAs($admin)
        ->put('/admin/security/password', [
            'current_password' => 'secret-pass',
            'password' => 'New-Str0ng-Passw0rd!',
            'password_confirmation' => 'New-Str0ng-Passw0rd!',
        ])
        ->assertRedirect();

    expect(Hash::check('New-Str0ng-Passw0rd!', $admin->fresh()->password))->toBeTrue();
});

// --- Confine allow-list: operator preference actions -----------------------

it('lets the confined operator reach the language switch and ui-settings routes', function () {
    // Register runtime routes with the real names behind the confine, the same way
    // the host wires larafoundry.confine_admin on its web group.
    Route::middleware(['web', 'larafoundry.confine_admin'])->group(function () {
        Route::get('/test-language-switch', fn () => 'lang')->name('larafoundry.language.switch');
        Route::get('/test-ui-settings', fn () => 'ui')->name('profile.ui-settings.update');
    });
    Route::getRoutes()->refreshNameLookups();

    [$admin] = securityAdmin();

    $this->actingAs($admin)->get('/test-language-switch')->assertOk()->assertSee('lang');
    $this->actingAs($admin)->get('/test-ui-settings')->assertOk()->assertSee('ui');
});
