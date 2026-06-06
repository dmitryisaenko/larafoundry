<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Dmitryisaenko\LaraFoundry\Http\Middleware\EnsureAdminOtpVerified;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

// --- Helpers ---------------------------------------------------------------

/** A super-admin, optionally with confirmed Fortify 2FA enabled. */
function otpAdmin(bool $with2fa = true, array $recovery = ['ZZZZZ-AAAAA', 'YYYYY-BBBBB']): array
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
            'two_factor_recovery_codes' => Crypt::encrypt(json_encode($recovery)),
            'two_factor_confirmed_at' => now(),
        ])->save();
    }

    return [$user, $secret];
}

/** Build a request with a started session, resolved user, on the /admin path. */
function otpRequest(?User $user, bool $json = false, array $session = []): Request
{
    $store = new Store('larafoundry_session', new ArraySessionHandler(120), str_pad('s', 40, 'a'));
    $store->start();
    foreach ($session as $key => $value) {
        $store->put($key, $value);
    }

    $server = $json ? ['HTTP_ACCEPT' => 'application/json'] : [];
    $request = Request::create('/admin', 'GET', server: $server);
    $request->setLaravelSession($store);
    $request->setUserResolver(fn () => $user);

    return $request;
}

function runOtpGate(Request $request): mixed
{
    return (new EnsureAdminOtpVerified(app(VisitorStatus::class)))
        ->handle($request, fn () => response('console'));
}

// --- Gate (middleware) -----------------------------------------------------

it('passes a normal (non-admin) user straight through', function () {
    $user = User::create(['name' => 'U', 'email' => 'u@x.test', 'password' => 'secret-pass']);

    expect(runOtpGate(otpRequest($user))->getContent())->toBe('console');
});

it('passes the super-admin through once the step-up is cleared this session', function () {
    [$admin] = otpAdmin();

    $response = runOtpGate(otpRequest($admin, session: [EnsureAdminOtpVerified::SESSION_KEY => true]));

    expect($response->getContent())->toBe('console');
});

it('redirects an unverified super-admin to the OTP challenge', function () {
    [$admin] = otpAdmin();

    $response = runOtpGate(otpRequest($admin));

    expect($response->isRedirect())->toBeTrue()
        ->and($response->headers->get('Location'))->toContain('/admin/otp');
});

it('sends a super-admin without 2FA to the configured setup route', function () {
    Route::get('/setup-2fa', fn () => 'setup')->name('test.2fa.setup');
    Route::getRoutes()->refreshNameLookups();
    config()->set('larafoundry.security.super_admin.two_factor_setup_route', 'test.2fa.setup');

    [$admin] = otpAdmin(with2fa: false);

    $response = runOtpGate(otpRequest($admin));

    expect($response->isRedirect())->toBeTrue()
        ->and($response->headers->get('Location'))->toContain('/setup-2fa');
});

it('denies a super-admin without 2FA when no setup route is configured (fail closed)', function () {
    config()->set('larafoundry.security.super_admin.two_factor_setup_route', null);

    [$admin] = otpAdmin(with2fa: false);

    runOtpGate(otpRequest($admin));
})->throws(HttpException::class);

it('passes through entirely when require_otp is off', function () {
    config()->set('larafoundry.security.super_admin.require_otp', false);

    [$admin] = otpAdmin(with2fa: false);

    expect(runOtpGate(otpRequest($admin))->getContent())->toBe('console');
});

it('answers a JSON request with 403 instead of redirecting to the challenge', function () {
    [$admin] = otpAdmin();

    runOtpGate(otpRequest($admin, json: true));
})->throws(HttpException::class);

// --- Challenge controller (HTTP) -------------------------------------------

it('verifies a valid TOTP code and clears the step-up', function () {
    [$admin, $secret] = otpAdmin();

    $code = (new Google2FA)->getCurrentOtp($secret);

    $this->actingAs($admin)
        ->post('/admin/otp', ['code' => $code])
        ->assertRedirect();

    expect(session(EnsureAdminOtpVerified::SESSION_KEY))->toBeTrue();
});

it('verifies a single-use recovery code and consumes it', function () {
    [$admin] = otpAdmin(recovery: ['ZZZZZ-AAAAA', 'YYYYY-BBBBB']);

    $this->actingAs($admin)
        ->post('/admin/otp', ['recovery_code' => 'ZZZZZ-AAAAA'])
        ->assertRedirect();

    expect(session(EnsureAdminOtpVerified::SESSION_KEY))->toBeTrue();

    // The used code is gone; the other remains.
    $remaining = $admin->fresh()->recoveryCodes();
    expect($remaining)->not->toContain('ZZZZZ-AAAAA')
        ->and($remaining)->toContain('YYYYY-BBBBB');
});

it('rejects an invalid code without clearing the step-up', function () {
    [$admin] = otpAdmin();

    $this->actingAs($admin)
        ->from('/admin/otp')
        ->post('/admin/otp', ['code' => '000000'])
        ->assertRedirect('/admin/otp')
        ->assertSessionHasErrors('code');

    expect(session(EnsureAdminOtpVerified::SESSION_KEY))->toBeNull();
});

// --- Wiring: the console really sits behind the gate -----------------------

it('routes the console behind the OTP gate (dashboard demands the step-up)', function () {
    config()->set('inertia.testing.ensure_pages_exist', false);
    [$admin] = otpAdmin();

    $this->actingAs($admin)
        ->get('/admin', ['X-Inertia' => 'true'])
        ->assertRedirect(route('admin.otp.show'));
});

it('gates every console surface, not only the dashboard', function () {
    config()->set('inertia.testing.ensure_pages_exist', false);
    config()->set('larafoundry-activitylog.geo.enabled', false);
    [$admin] = otpAdmin();
    $target = User::create(['name' => 'Joe', 'email' => 'joe@x.test', 'password' => 'secret-pass']);

    foreach (['/admin/users', '/admin/companies', '/admin/activity-log'] as $url) {
        $this->actingAs($admin)
            ->get($url, ['X-Inertia' => 'true'])
            ->assertRedirect(route('admin.otp.show'));
    }

    $this->actingAs($admin)
        ->post("/admin/impersonate/{$target->id}")
        ->assertRedirect(route('admin.otp.show'));
});

it('opens the console once the step-up flag is set in the session', function () {
    config()->set('inertia.testing.ensure_pages_exist', false);
    config()->set('larafoundry-activitylog.geo.enabled', false);
    [$admin] = otpAdmin();

    $this->actingAs($admin)
        ->withSession([EnsureAdminOtpVerified::SESSION_KEY => true])
        ->get('/admin', ['X-Inertia' => 'true'])
        ->assertOk()
        ->assertJsonPath('component', 'Admin/Dashboard');
});

// --- Session flag lifecycle ------------------------------------------------

it('drops the step-up flag on a fresh login and on logout', function () {
    [$admin] = otpAdmin();

    session()->put(EnsureAdminOtpVerified::SESSION_KEY, true);
    event(new Login('web', $admin, false));
    expect(session(EnsureAdminOtpVerified::SESSION_KEY))->toBeNull();

    session()->put(EnsureAdminOtpVerified::SESSION_KEY, true);
    event(new Logout('web', $admin));
    expect(session(EnsureAdminOtpVerified::SESSION_KEY))->toBeNull();
});
