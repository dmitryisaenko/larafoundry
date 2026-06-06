<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Http\Controllers\SessionController;
use Dmitryisaenko\LaraFoundry\Auth\Models\UserSession;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

/**
 * Drive the controller directly with a request whose session id we control —
 * the same harness SessionControllerTest uses, so we can assert the
 * current-session guard without a real HTTP round trip regenerating the id.
 */
function revokeRequest(string $currentSessionId, User $user): Request
{
    $store = new Store('larafoundry_session', new ArraySessionHandler(120), $currentSessionId);
    $store->start();

    $request = Request::create('/auth/sessions/revoke', 'DELETE');
    $request->setLaravelSession($store);
    $request->setUserResolver(fn () => $user);

    return $request;
}

it('revokes a non-current session of the user', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    $current = str_pad('cur', 40, 'a');
    $user->sessions()->create(['session_id' => $current, 'login_method' => 'native']);
    $other = $user->sessions()->create(['session_id' => str_pad('oth', 40, 'b'), 'login_method' => 'native']);

    $response = (new SessionController)->destroy(revokeRequest($current, $user), $other);

    expect($response->isRedirect())->toBeTrue()
        ->and(UserSession::find($other->id))->toBeNull();
});

it('aborts 404 when revoking another user\'s session (IDOR)', function () {
    $owner = User::create(['name' => 'O', 'email' => 'o@x.test', 'password' => 'secret-pass']);
    $attacker = User::create(['name' => 'X', 'email' => 'x@x.test', 'password' => 'secret-pass']);
    $session = $owner->sessions()->create(['session_id' => str_pad('s', 40, 'a'), 'login_method' => 'native']);

    expect(fn () => (new SessionController)->destroy(revokeRequest(str_pad('cur', 40, 'c'), $attacker), $session))
        ->toThrow(HttpException::class);

    expect(UserSession::find($session->id))->not->toBeNull();
});

it('does not revoke the current session', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    $current = str_pad('cur', 40, 'a');
    $session = $user->sessions()->create(['session_id' => $current, 'login_method' => 'native']);

    $response = (new SessionController)->destroy(revokeRequest($current, $user), $session);

    expect($response->isRedirect())->toBeTrue()
        ->and(UserSession::find($session->id))->not->toBeNull();
});
