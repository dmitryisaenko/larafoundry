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

/**
 * destroyToken() takes a token id, not a route-model-bound instance, so a plain
 * request carrying the user resolver is enough to drive it.
 */
function tokenRevokeRequest(User $user): Request
{
    $request = Request::create('/auth/tokens/1', 'DELETE');
    $request->setUserResolver(fn () => $user);

    return $request;
}

it('revokes an API-token device of the user', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    $token = $user->createToken('Android · SM-M127F')->accessToken;

    $response = (new SessionController)->destroyToken(tokenRevokeRequest($user), $token->id);

    expect($response->isRedirect())->toBeTrue()
        ->and($user->tokens()->whereKey($token->id)->exists())->toBeFalse();
});

it('aborts 404 when revoking another user\'s API token (IDOR)', function () {
    $owner = User::create(['name' => 'O', 'email' => 'o@x.test', 'password' => 'secret-pass']);
    $attacker = User::create(['name' => 'X', 'email' => 'x@x.test', 'password' => 'secret-pass']);
    $token = $owner->createToken('mobile')->accessToken;

    expect(fn () => (new SessionController)->destroyToken(tokenRevokeRequest($attacker), $token->id))
        ->toThrow(HttpException::class);

    expect($owner->tokens()->whereKey($token->id)->exists())->toBeTrue();
});

it('aborts 404 when revoking a missing API token', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    expect(fn () => (new SessionController)->destroyToken(tokenRevokeRequest($user), 999999))
        ->toThrow(HttpException::class);
});
