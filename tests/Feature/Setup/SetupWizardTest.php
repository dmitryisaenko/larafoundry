<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Admin\Http\Controllers\ImpersonateController;
use Dmitryisaenko\LaraFoundry\Admin\Support\ActingAsSomeoneElse;
use Dmitryisaenko\LaraFoundry\Http\Middleware\HandleInertiaRequests;
use Dmitryisaenko\LaraFoundry\Setup\Support\SetupWizard;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * The first-login setup wizard (v0.49.0): a login marks the session, the shared
 * prop lists the steps still to ask, and the three ways out (skip, done, again)
 * hold across requests. A host adds steps and parts; a part asked only on some
 * requests is finished only there.
 */
uses(RefreshDatabase::class);

function setupUser(array $attributes = []): User
{
    return User::create(array_merge([
        'name' => 'Ann',
        'email' => 'ann@x.test',
        'password' => 'secret-pass',
    ], $attributes));
}

/**
 * The shared prop as a page would receive it: the core middleware's share over a
 * request carrying this user and this session (the harness does not run the
 * middleware itself).
 *
 * @param  array<string, mixed>  $session
 */
function setupProp(User $user, array $session = [], string $url = 'http://localhost/profile'): mixed
{
    $store = new Store('test', new ArraySessionHandler(60));

    foreach ($session as $key => $value) {
        $store->put($key, $value);
    }

    $request = Request::create($url);
    $request->setLaravelSession($store);
    $request->setUserResolver(fn () => $user);

    return ((new HandleInertiaRequests)->share($request)['setup_wizard'])();
}

function wizard(): SetupWizard
{
    return app(SetupWizard::class);
}

it('marks the session at login and offers the core steps in order', function () {
    $user = setupUser();

    Auth::guard('web')->login($user);

    expect(session(SetupWizard::SESSION_KEY))->toBeTrue()
        ->and(setupProp($user, [SetupWizard::SESSION_KEY => session(SetupWizard::SESSION_KEY)]))->toBe(['theme', 'locale', 'about', 'date']);
});

it('stays shut without a login mark - it is once per login, not on every page', function () {
    expect(setupProp(setupUser()))->toBeFalse();
});

it('does not mark a login once every part is finished', function () {
    $user = setupUser(['setup_done' => ['common' => '2026-10-31 10:00:00']]);

    Auth::guard('web')->login($user);

    expect(session()->has(SetupWizard::SESSION_KEY))->toBeFalse();
});

it('does not mark a login while the wizard is switched off, and offers nothing', function () {
    config()->set('larafoundry.setup_wizard.enabled', false);
    $user = setupUser();

    Auth::guard('web')->login($user);

    expect(session()->has(SetupWizard::SESSION_KEY))->toBeFalse()
        ->and(setupProp($user, [SetupWizard::SESSION_KEY => true]))->toBeFalse();
});

it('does not register its routes while switched off', function () {
    config()->set('larafoundry.setup_wizard.enabled', false);

    $router = new Router(app('events'), app());
    Route::swap($router);

    require __DIR__.'/../../../routes/setup.php';
    $router->getRoutes()->refreshNameLookups();

    expect($router->getRoutes()->getByName('larafoundry.setup-wizard.done'))->toBeNull();
});

it('opens with the user\'s own name, e-mail and the host\'s extra fields', function () {
    wizard()->addState(fn ($user) => ['learning' => 'de:'.$user->name]);

    $this->actingAs(setupUser(['lastname' => 'Lee']))
        ->getJson('/setup-wizard/state')
        ->assertOk()
        ->assertJson(['name' => 'Ann', 'lastname' => 'Lee', 'email' => 'ann@x.test', 'learning' => 'de:Ann']);
});

it('"Skip" shuts it for this login even when a late request writes the session key back', function () {
    $user = setupUser();
    $session = [SetupWizard::SESSION_KEY => true, SetupWizard::LOGIN_KEY => 'login-1'];

    $this->actingAs($user)->withSession($session)->postJson('/setup-wizard/later')->assertOk();

    expect($user->fresh()->setup_done)->toBeNull()
        // A request that read the session before the skip carries the key back.
        ->and(setupProp($user, $session))->toBeFalse()
        // The next login is a new mark, so the wizard is offered again.
        ->and(setupProp($user, [SetupWizard::SESSION_KEY => true, SetupWizard::LOGIN_KEY => 'login-2']))
        ->toBe(['theme', 'locale', 'about', 'date']);
});

it('"Done" finishes the part for good', function () {
    $user = setupUser();

    $this->actingAs($user)
        ->withSession([SetupWizard::SESSION_KEY => true])
        ->postJson('/setup-wizard/done')
        ->assertOk();

    expect($user->fresh()->setup_done)->toHaveKey('common')
        ->and(setupProp($user->fresh(), [SetupWizard::SESSION_KEY => true]))->toBeFalse();
});

it('"Go through the setup again" clears the marks and opens it at once', function () {
    $user = setupUser(['setup_done' => ['common' => '2026-10-31 10:00:00']]);

    $this->actingAs($user)->postJson('/setup-wizard/again')->assertOk();

    expect($user->fresh()->setup_done)->toBeNull()
        ->and(session(SetupWizard::SESSION_KEY))->toBeTrue();
});

it('lets the profile offer "again" only while there is a step to ask, under the app name', function () {
    config()->set('app.name', 'Acme');
    $user = setupUser();

    $this->actingAs($user)->withHeader('X-Inertia', 'true')->get('/profile')
        ->assertJsonPath('props.setupWizard', 'Acme');

    config()->set('larafoundry.setup_wizard.enabled', false);

    $this->actingAs($user)->withHeader('X-Inertia', 'true')->get('/profile')
        ->assertJsonPath('props.setupWizard', null);
});

it('stays shut while an operator is signed in as the user, or a host says so', function () {
    $user = setupUser();

    expect(setupProp($user, [
        SetupWizard::SESSION_KEY => true,
        ImpersonateController::SESSION_KEY => 99,
    ]))->toBeFalse();

    wizard()->hideWhen(fn (Request $request) => $request->session()->has('host.follow'));

    expect(setupProp($user, [SetupWizard::SESSION_KEY => true, 'host.follow' => 1]))
        ->toBeFalse();
});

it('writes nothing on the user\'s account for an operator signed in as them (review 2026-10-31)', function () {
    $user = setupUser(['setup_done' => ['common' => '2026-10-31 10:00:00']]);
    app(ActingAsSomeoneElse::class)->addCheck(fn (Request $request) => $request->session()->has('host.follow'));

    foreach ([[ImpersonateController::SESSION_KEY => 99], ['host.follow' => 1]] as $session) {
        foreach (['later', 'done', 'again'] as $action) {
            $this->actingAs($user)->withSession($session)->postJson("/setup-wizard/{$action}")->assertForbidden();
        }

        $this->actingAs($user)->withSession($session)->withHeader('X-Inertia', 'true')->get('/profile')
            ->assertJsonPath('props.setupWizard', null);
    }

    expect($user->fresh()->setup_done)->toBe(['common' => '2026-10-31 10:00:00']);
});

it('takes host steps in their place, and a step can be dropped', function () {
    wizard()->addStep('translate', 30)->removeStep('date');

    expect(setupProp(setupUser(), [SetupWizard::SESSION_KEY => true]))
        ->toBe(['theme', 'locale', 'translate', 'about']);
});

it('asks a host part only where it is asked, and finishing here leaves it unfinished', function () {
    wizard()->addStep('learning', 40, 'lessons')
        ->askPartWhen('lessons', fn (Request $request) => $request->getHost() === 'lessons.test');

    $user = setupUser();
    $session = [SetupWizard::SESSION_KEY => true];

    expect(setupProp($user, $session))->toBe(['theme', 'locale', 'about', 'date']);

    $this->actingAs($user)->withSession($session)->postJson('/setup-wizard/done')->assertOk();

    expect($user->fresh()->setup_done)->toHaveKey('common')->not->toHaveKey('lessons');

    // The login still marks the session: one part is unfinished somewhere.
    Auth::guard('web')->login($user->fresh());
    expect(session(SetupWizard::SESSION_KEY))->toBeTrue();

    expect(setupProp($user->fresh(), $session, 'http://lessons.test/profile'))->toBe(['learning']);
});

it('asks a step added to a finished part only of people who have not finished it', function () {
    $user = setupUser(['setup_done' => ['common' => '2026-10-31 10:00:00']]);

    wizard()->addStep('translate', 30);

    expect(wizard()->stepsFor($user, null))->toBe([])
        ->and(wizard()->stepsFor(setupUser(['email' => 'new@x.test']), null))
        ->toBe(['theme', 'locale', 'translate', 'about', 'date']);
});

it('keeps a guest out of every endpoint', function () {
    $this->getJson('/setup-wizard/state')->assertUnauthorized();
    $this->postJson('/setup-wizard/done')->assertUnauthorized();
    $this->postJson('/setup-wizard/again')->assertUnauthorized();
});
