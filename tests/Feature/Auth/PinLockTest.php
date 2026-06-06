<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Http\Controllers\PinController;
use Dmitryisaenko\LaraFoundry\Auth\Http\Middleware\CheckPinLock;
use Dmitryisaenko\LaraFoundry\Auth\Models\UserSession;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

// --- Helpers ---------------------------------------------------------------

function pinUser(?string $pin = '1234'): User
{
    $user = User::create(['name' => 'P', 'email' => 'p'.uniqid().'@x.test', 'password' => 'secret-pass']);

    if ($pin !== null) {
        $user->forceFill(['pin_code' => Hash::make($pin)])->save();
    }

    return $user;
}

/** A valid Laravel session id (40 alphanumerics) derived from a short seed. */
function sid(string $seed): string
{
    return str_pad((string) preg_replace('/[^a-z0-9]/', '', $seed), 40, '0');
}

function pinSession(User $user, string $sessionId, array $attrs = []): UserSession
{
    return UserSession::create(array_merge([
        'user_id' => $user->getKey(),
        'session_id' => sid($sessionId),
        'login_method' => 'native',
        'last_activity' => now(),
    ], $attrs));
}

function pinRequest(User $user, string $sessionId, string $method = 'GET', array $params = [], bool $json = false): Request
{
    $store = new Store('larafoundry_session', new ArraySessionHandler(120), sid($sessionId));
    $store->start();

    $server = $json ? ['HTTP_ACCEPT' => 'application/json'] : [];
    $request = Request::create('/dashboard', $method, $params, server: $server);
    $request->setLaravelSession($store);
    $request->setUserResolver(fn () => $user);

    return $request;
}

function runPinLock(Request $request): mixed
{
    return (new CheckPinLock)->handle($request, fn () => response('passed'));
}

// --- User predicates -------------------------------------------------------

it('reports hasPin and verifies the PIN against the hash', function () {
    $user = pinUser('4321');

    expect($user->hasPin())->toBeTrue()
        ->and($user->checkPinCode('4321'))->toBeTrue()
        ->and($user->checkPinCode('0000'))->toBeFalse();

    expect(pinUser(null)->hasPin())->toBeFalse();
});

// --- CheckPinLock middleware ----------------------------------------------

it('is a no-op for a user without a PIN', function () {
    expect(runPinLock(pinRequest(pinUser(null), 'sess-a'))->getContent())->toBe('passed');
});

it('is a no-op when PIN-lock is disabled', function () {
    config()->set('larafoundry.pin.enabled', false);
    $user = pinUser();
    pinSession($user, 'sess-b', ['last_activity' => now()->subHour()]);

    expect(runPinLock(pinRequest($user, 'sess-b'))->getContent())->toBe('passed');
});

it('auto-locks the session after the idle timeout and redirects to PIN entry', function () {
    config()->set('larafoundry.pin.idle_timeout', 1800);
    $user = pinUser();
    $session = pinSession($user, 'sess-c', ['last_activity' => now()->subSeconds(2000)]);

    $response = runPinLock(pinRequest($user, 'sess-c'));

    expect($response->isRedirect())->toBeTrue()
        ->and($response->headers->get('Location'))->toContain('/pin')
        ->and($session->fresh()->pin_locked)->toBeTrue();
});

it('does not lock a session that is still within the idle window', function () {
    config()->set('larafoundry.pin.idle_timeout', 1800);
    $user = pinUser();
    pinSession($user, 'sess-d', ['last_activity' => now()->subSeconds(60)]);

    expect(runPinLock(pinRequest($user, 'sess-d'))->getContent())->toBe('passed');
});

it('redirects an already-locked session to PIN entry', function () {
    $user = pinUser();
    pinSession($user, 'sess-e', ['pin_locked' => true]);

    expect(runPinLock(pinRequest($user, 'sess-e'))->isRedirect())->toBeTrue();
});

it('answers a JSON request on a locked session with 423', function () {
    $user = pinUser();
    pinSession($user, 'sess-f', ['pin_locked' => true]);

    runPinLock(pinRequest($user, 'sess-f', json: true));
})->throws(HttpException::class);

// --- PinController ----------------------------------------------------------

it('enables a PIN, storing it hashed', function () {
    $user = pinUser(null);
    Auth::login($user);

    (new PinController)->enable(pinRequest($user, 'sess-g', 'POST', ['pin' => '5678']));

    $user->refresh();
    expect($user->hasPin())->toBeTrue()
        ->and($user->pin_code)->not->toBe('5678')
        ->and(Hash::check('5678', $user->pin_code))->toBeTrue();
});

it('refuses to change the PIN from a locked session (no unlock-by-reset bypass)', function () {
    $user = pinUser('1234');
    Auth::login($user);
    $session = pinSession($user, 'sess-lock', ['pin_locked' => true]);

    expect(fn () => (new PinController)->enable(pinRequest($user, 'sess-lock', 'POST', ['pin' => '9999'])))
        ->toThrow(ValidationException::class);

    // PIN unchanged, session still locked.
    expect($user->fresh()->checkPinCode('1234'))->toBeTrue()
        ->and($session->fresh()->pin_locked)->toBeTrue();
});

it('unlocks this session on a correct PIN and resets the attempt counter', function () {
    $user = pinUser('1234');
    Auth::login($user);
    $session = pinSession($user, 'sess-h', ['pin_locked' => true, 'pin_attempts' => 3]);

    $response = (new PinController)->check(pinRequest($user, 'sess-h', 'POST', ['pin' => '1234']));

    expect($response->isRedirect())->toBeTrue();
    $session->refresh();
    expect($session->pin_locked)->toBeFalse()
        ->and($session->pin_attempts)->toBe(0);
});

it('counts a wrong PIN and keeps the session locked', function () {
    $user = pinUser('1234');
    Auth::login($user);
    $session = pinSession($user, 'sess-i', ['pin_locked' => true]);

    expect(fn () => (new PinController)->check(pinRequest($user, 'sess-i', 'POST', ['pin' => '0000'])))
        ->toThrow(ValidationException::class);

    expect($session->fresh()->pin_attempts)->toBe(1)
        ->and($session->fresh()->pin_locked)->toBeTrue();
});

it('locks out PIN entry after the max attempts', function () {
    config()->set('larafoundry.pin.max_attempts', 3);
    config()->set('larafoundry.pin.lockout_minutes', 15);
    $user = pinUser('1234');
    Auth::login($user);
    $session = pinSession($user, 'sess-j', ['pin_locked' => true, 'pin_attempts' => 2]);

    // Third wrong attempt trips the lockout window.
    expect(fn () => (new PinController)->check(pinRequest($user, 'sess-j', 'POST', ['pin' => '0000'])))
        ->toThrow(ValidationException::class);

    $session->refresh();
    expect($session->pin_locked_until)->not->toBeNull()
        ->and($session->pin_locked_until->isFuture())->toBeTrue();

    // Even a CORRECT PIN is refused while locked out.
    expect(fn () => (new PinController)->check(pinRequest($user, 'sess-j', 'POST', ['pin' => '1234'])))
        ->toThrow(ValidationException::class);
});

it('removes the PIN and unlocks all sessions on disable with the correct PIN', function () {
    $user = pinUser('1234');
    Auth::login($user);
    pinSession($user, 'sess-k1', ['pin_locked' => true]);
    pinSession($user, 'sess-k2', ['pin_locked' => true]);

    (new PinController)->disable(pinRequest($user, 'sess-k1', 'POST', ['pin' => '1234']));

    expect($user->fresh()->hasPin())->toBeFalse()
        ->and(UserSession::where('user_id', $user->getKey())->where('pin_locked', true)->count())->toBe(0);
});

it('refuses to disable with a wrong PIN', function () {
    $user = pinUser('1234');
    Auth::login($user);

    expect(fn () => (new PinController)->disable(pinRequest($user, 'sess-l', 'POST', ['pin' => '0000'])))
        ->toThrow(ValidationException::class);

    expect($user->fresh()->hasPin())->toBeTrue();
});

it('manual lock flags every session; unlock frees only the current device', function () {
    $user = pinUser('1234');
    Auth::login($user);
    $a = pinSession($user, 'sess-m1');
    $b = pinSession($user, 'sess-m2');

    // Lock everywhere.
    (new PinController)->lock(pinRequest($user, 'sess-m1', 'POST'));
    expect($a->fresh()->pin_locked)->toBeTrue()
        ->and($b->fresh()->pin_locked)->toBeTrue();

    // Unlock only device A by entering the PIN there.
    (new PinController)->check(pinRequest($user, 'sess-m1', 'POST', ['pin' => '1234']));
    expect($a->fresh()->pin_locked)->toBeFalse()
        ->and($b->fresh()->pin_locked)->toBeTrue();
});
