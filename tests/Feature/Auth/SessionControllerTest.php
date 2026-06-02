<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Http\Controllers\SessionController;
use Dmitryisaenko\LaraFoundry\Auth\Models\UserSession;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

it('keeps only the current session id and deletes the others', function () {
    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);

    $currentId = str_pad('current', 40, 'a');

    foreach ([$currentId, str_pad('other1', 40, 'b'), str_pad('other2', 40, 'c')] as $id) {
        $user->sessions()->create(['session_id' => $id, 'login_method' => 'native']);
    }

    expect($user->sessions()->count())->toBe(3);

    // Drive the controller directly with a request whose session id is the one
    // we want preserved (a real HTTP request regenerates the id per call, which
    // would never match our pre-seeded rows).
    $store = new Store('larafoundry_session', new ArraySessionHandler(120), $currentId);
    $store->start();

    $request = Request::create('/auth/sessions/others', 'DELETE');
    $request->setLaravelSession($store);
    $request->setUserResolver(fn () => $user);

    $response = (new SessionController)->destroyOthers($request);

    expect($response->isRedirect())->toBeTrue();

    expect($user->sessions()->pluck('session_id')->all())->toBe([$currentId]);
    expect(UserSession::count())->toBe(1);
});

it('prunes the framework sessions table for other devices on the database driver', function () {
    config()->set('session.driver', 'database');

    $user = User::create(['name' => 'A', 'email' => 'a@x.test', 'password' => 'secret-pass']);
    $currentId = str_pad('current', 40, 'a');
    $otherId = str_pad('other', 40, 'b');

    foreach ([$currentId, $otherId] as $id) {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user->id,
            'payload' => 'x',
            'last_activity' => time(),
        ]);
    }

    $store = new Store('larafoundry_session', new ArraySessionHandler(120), $currentId);
    $store->start();

    $request = Request::create('/auth/sessions/others', 'DELETE');
    $request->setLaravelSession($store);
    $request->setUserResolver(fn () => $user);

    (new SessionController)->destroyOthers($request);

    $remaining = DB::table('sessions')->pluck('id')->all();
    expect($remaining)->toBe([$currentId]);
});

it('aborts when no user is authenticated', function () {
    $request = Request::create('/auth/sessions/others', 'DELETE');
    $request->setUserResolver(fn () => null);

    expect(fn () => (new SessionController)->destroyOthers($request))
        ->toThrow(HttpException::class);
});

it('redirects guests away from the route (auth middleware)', function () {
    $this->delete(route('larafoundry.sessions.destroy-others'))
        ->assertStatus(302);
});
