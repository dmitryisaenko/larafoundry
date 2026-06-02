<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Listeners\RecordUserSession;
use Dmitryisaenko\LaraFoundry\Auth\Models\UserSession;
use Dmitryisaenko\LaraFoundry\Auth\Support\DeviceFingerprint;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;

uses(RefreshDatabase::class);

/**
 * Build a request carrying a started session with a known, valid id + a chrome
 * UA, and bind it as the container's current request so the listener (which
 * reads request()/request()->session()) sees it.
 *
 * Session ids must be 40-char alphanumeric or the Store rejects them and
 * generates its own — so we build a valid id from the caller's label.
 */
function loginRequest(string $label): Request
{
    $id = str_pad(preg_replace('/[^a-zA-Z0-9]/', '', $label), 40, 'a');

    $store = new Store('larafoundry_session', new ArraySessionHandler(120), $id);
    $store->start();

    $request = Request::create('/login', 'POST', server: [
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36',
        'REMOTE_ADDR' => '203.0.113.7',
    ]);
    $request->setLaravelSession($store);

    app()->instance('request', $request);

    return $request;
}

/** Fire the framework Login event through the registered listener. */
function fireLogin(User $user): void
{
    app(RecordUserSession::class)->handle(new Login('web', $user, false));
}

it('records a UserSession keyed to the current session id with device data', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    $request = loginRequest('sess-aaa');
    fireLogin($user);

    $session = $user->sessions()->first();

    expect($session)->not->toBeNull()
        ->and($session->session_id)->toBe($request->session()->getId())
        ->and($session->login_method)->toBe('native')
        ->and($session->ip_address)->toBe('203.0.113.7')
        ->and($session->user_device_type)->toBe('desktop')
        ->and($session->user_browser)->toBe('Chrome')
        ->and($session->user_os)->toBe('Windows 10/11');
});

it('stamps last_login_at / last_activity_at on the user', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    expect($user->fresh()->last_login_at)->toBeNull();

    loginRequest('sess-bbb');
    fireLogin($user);

    expect($user->fresh()->last_login_at)->not->toBeNull()
        ->and($user->fresh()->last_activity_at)->not->toBeNull();
});

it('does not duplicate the row on a repeat with the same session id', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    loginRequest('sess-ccc');
    fireLogin($user);
    loginRequest('sess-ccc');
    fireLogin($user);

    expect($user->sessions()->count())->toBe(1);
});

it('records the OAuth provider as login_method when the session hint is set', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    $request = loginRequest('sess-oauth');
    $request->session()->put(RecordUserSession::METHOD_HINT, 'google');

    fireLogin($user);

    $session = $user->sessions()->first();

    expect($session->login_method)->toBe('google')
        // The hint is single-use (pull): it must not linger in the session.
        ->and($request->session()->has(RecordUserSession::METHOD_HINT))->toBeFalse();
});

it('covers the registration auto-login path through the same listener', function () {
    // Fortify's register action calls Auth::login(), firing the same Login event
    // this listener subscribes to. So driving the listener with a Login event is
    // exactly what registration triggers — register tracking is covered here.
    $user = User::create(['name' => 'New', 'email' => 'new@x.test', 'password' => 'secret-pass']);

    loginRequest('sess-register');
    fireLogin($user);

    expect($user->sessions()->count())->toBe(1)
        ->and($user->sessions()->first()->login_method)->toBe('native');
});

it('does nothing when the authenticated subject is not a tracking user', function () {
    // A bare authenticatable without the trait's recordSession() must be a no-op,
    // not an error — the listener guards with method_exists().
    $plain = new class extends Illuminate\Foundation\Auth\User {};

    loginRequest('sess-plain');
    app(RecordUserSession::class)->handle(new Login('web', $plain, false));

    expect(UserSession::count())->toBe(0);
});

it('writes the row directly through the trait recordSession() writer', function () {
    // Exercise the single writer in isolation of the listener/event plumbing.
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    $device = new DeviceFingerprint(
        type: 'mobile', name: 'iPhone', os: 'iOS', browser: 'Safari',
    );

    $user->recordSession('direct-writer-session-id', $device, 'apple', '198.51.100.9', 'UA/1.0');

    $session = $user->sessions()->first();

    expect($session)->not->toBeNull()
        ->and($session->session_id)->toBe('direct-writer-session-id')
        ->and($session->login_method)->toBe('apple')
        ->and($session->ip_address)->toBe('198.51.100.9')
        ->and($session->user_agent)->toBe('UA/1.0')
        ->and($session->user_device_type)->toBe('mobile')
        ->and($session->user_os)->toBe('iOS')
        ->and($user->fresh()->last_login_at)->not->toBeNull()
        ->and($user->fresh()->last_activity_at)->not->toBeNull();

    // Dedup: the same session id updates in place rather than inserting again.
    $user->recordSession('direct-writer-session-id', $device, 'apple');

    expect($user->sessions()->count())->toBe(1);
});
