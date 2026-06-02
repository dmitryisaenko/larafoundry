<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Http\Middleware\ReconcileTrackedSession;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Suppress the RecordUserSession listener: logging a user in to assert
    // logout would otherwise auto-record a session row for the global request,
    // contaminating the row set this middleware reconciles against.
    Event::fake([Login::class]);
});

/**
 * A request whose session id is $sessionId, with $user (or guest) resolved.
 */
function reconcileRequest(?User $user, string $sessionId, bool $json = false): Request
{
    $store = new Store('larafoundry_session', new ArraySessionHandler(120), $sessionId);
    $store->start();

    $server = $json ? ['HTTP_ACCEPT' => 'application/json'] : [];
    $request = Request::create('/dashboard', 'GET', server: $server);
    $request->setLaravelSession($store);
    $request->setUserResolver(fn () => $user);

    return $request;
}

function runReconcile(Request $request): mixed
{
    return (new ReconcileTrackedSession)->handle($request, fn (Request $r) => response('passed'));
}

it('passes a user whose current session id is tracked', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    Auth::login($user);

    $id = str_pad('current', 40, 'a');
    $user->sessions()->create(['session_id' => $id, 'login_method' => 'native']);

    $response = runReconcile(reconcileRequest($user, $id));

    expect($response->getContent())->toBe('passed')
        ->and(Auth::check())->toBeTrue();
});

it('logs out a user whose session row was revoked (only other devices remain)', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    Auth::login($user);

    // A row exists for another device, but NOT for this request's session id.
    $user->sessions()->create(['session_id' => str_pad('other', 40, 'b'), 'login_method' => 'native']);

    $current = str_pad('revoked', 40, 'c');
    $response = runReconcile(reconcileRequest($user, $current));

    expect($response->isRedirect())->toBeTrue()
        ->and(Auth::check())->toBeFalse();
});

it('aborts 401 on a JSON request when the session row was revoked', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    Auth::login($user);

    $user->sessions()->create(['session_id' => str_pad('other', 40, 'b'), 'login_method' => 'native']);

    expect(fn () => runReconcile(reconcileRequest($user, str_pad('revoked', 40, 'c'), json: true)))
        ->toThrow(HttpException::class);

    expect(Auth::check())->toBeFalse();
});

it('grace-passes a user who has no tracked sessions at all', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    Auth::login($user);

    $response = runReconcile(reconcileRequest($user, str_pad('fresh', 40, 'a')));

    expect($response->getContent())->toBe('passed')
        ->and(Auth::check())->toBeTrue();
});

it('passes a guest through untouched', function () {
    $response = runReconcile(reconcileRequest(null, str_pad('guest', 40, 'a')));

    expect($response->getContent())->toBe('passed');
});
