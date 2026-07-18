<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\MagicLink\Models\MagicLoginToken;
use Dmitryisaenko\LaraFoundry\Auth\MagicLink\Notifications\MagicLoginLinkNotification;
use Dmitryisaenko\LaraFoundry\Http\Middleware\HandleInertiaRequests;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('larafoundry.auth.magic_link.enabled', true);
    Notification::fake();
});

/**
 * Drive the request step and return the signed verify URL that was emailed. The
 * plaintext token lives ONLY inside that URL (the DB holds its hash), so this is
 * the only way to capture it — exactly what the recipient's inbox carries.
 */
function magicLinkUrlFor(string $email): string
{
    test()->post(route('magic-link.request'), ['email' => $email])->assertRedirect();

    $captured = '';
    Notification::assertSentOnDemand(
        MagicLoginLinkNotification::class,
        function (MagicLoginLinkNotification $notification, array $channels, object $notifiable) use (&$captured, $email): bool {
            if (($notifiable->routes['mail'] ?? null) === Str::lower(trim($email))) {
                $captured = $notification->url;
            }

            return true;
        },
    );

    return $captured;
}

it('request mints a hashed token and mails a link; the response is neutral', function () {
    $response = $this->post(route('magic-link.request'), ['email' => 'Someone@X.test']);

    $response->assertRedirect()->assertSessionHas('message-info');

    $token = MagicLoginToken::query()->first();
    expect($token)->not->toBeNull()
        ->and($token->email)->toBe('someone@x.test')       // lowercased
        ->and($token->token_hash)->toHaveLength(64)         // sha-256 hex
        ->and($token->consumed_at)->toBeNull();

    Notification::assertSentOnDemand(MagicLoginLinkNotification::class);
});

it('behaves identically for a non-existent email (anti-enumeration)', function () {
    // An account exists for one address; the other is unknown. Both requests must
    // mint a token, send a link and return the same neutral redirect.
    User::create(['name' => 'Has', 'email' => 'has@x.test', 'password' => 'secret-pass']);

    $known = $this->post(route('magic-link.request'), ['email' => 'has@x.test']);
    $unknown = $this->post(route('magic-link.request'), ['email' => 'nobody@x.test']);

    $known->assertRedirect()->assertSessionHas('message-info');
    $unknown->assertRedirect()->assertSessionHas('message-info');

    expect(MagicLoginToken::query()->where('email', 'has@x.test')->exists())->toBeTrue()
        ->and(MagicLoginToken::query()->where('email', 'nobody@x.test')->exists())->toBeTrue();

    Notification::assertSentOnDemandTimes(MagicLoginLinkNotification::class, 2);
});

it('mails the link in the requester active locale (regression: template locale)', function () {
    config()->set('larafoundry.locale.available', ['en', 'uk']);
    app()->setLocale('uk');

    $this->post(route('magic-link.request'), ['email' => 'loc@x.test'])->assertRedirect();

    $seen = null;
    Notification::assertSentOnDemand(
        MagicLoginLinkNotification::class,
        function (MagicLoginLinkNotification $notification) use (&$seen): bool {
            $seen = $notification->mailLocale;

            return true;
        },
    );

    // The notification must carry the active locale so the templated email
    // renders in it (not the default) — the property toMail() actually reads.
    expect($seen)->toBe('uk');
});

it('verify logs in an existing user and lands on the intended home (case: login)', function () {
    $user = User::create(['name' => 'Existing', 'email' => 'existing@x.test', 'password' => 'secret-pass']);

    $url = magicLinkUrlFor('existing@x.test');
    expect($url)->not->toBe('');

    $this->get($url)->assertRedirect();

    expect(Auth::check())->toBeTrue()
        ->and(Auth::id())->toBe($user->id)
        ->and(User::count())->toBe(1);
});

it('verify creates a new, email-verified account for an unknown email (case: registration)', function () {
    $url = magicLinkUrlFor('fresh@x.test');

    $this->get($url)->assertRedirect();

    $user = User::where('email', 'fresh@x.test')->first();
    expect($user)->not->toBeNull()
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->name)->toBe('fresh');            // name derived from the local part

    expect(Auth::id())->toBe($user->id);
});

it('rejects a tampered signature without logging in', function () {
    $url = magicLinkUrlFor('sig@x.test');

    // Flip a character in the token so the signature no longer matches.
    $tampered = preg_replace('/token=([^&]+)/', 'token=${1}x', $url);

    $this->get($tampered)->assertForbidden();
    $this->assertGuest();
});

it('rejects an expired link without logging in', function () {
    config()->set('larafoundry.auth.magic_link.ttl_minutes', 15);

    $url = magicLinkUrlFor('expired@x.test');

    // Past the signed-URL expiry: the signed middleware refuses before any DB work.
    $this->travelTo(now()->addMinutes(16));

    $this->get($url)->assertForbidden();
    $this->assertGuest();

    $this->travelBack();
});

it('is single-use: a replayed link is refused with a neutral error', function () {
    $url = magicLinkUrlFor('replay@x.test');

    // First click consumes the token and logs in.
    $this->get($url)->assertRedirect();
    expect(Auth::check())->toBeTrue();
    expect(MagicLoginToken::query()->first()->consumed_at)->not->toBeNull();

    // Fresh guest session; the SAME link must not work again (signature still
    // valid, but the token is consumed → neutral error, no login).
    Auth::logout();
    $this->app['auth']->forgetGuards();

    $this->get($url)->assertRedirect()->assertSessionHas('message-error');
    $this->assertGuest();

    expect(User::count())->toBe(1); // no second account minted on replay
});

it('throttles requests per email+ip', function () {
    config()->set('larafoundry.auth.magic_link.throttle', '5,1');

    for ($i = 0; $i < 5; $i++) {
        $this->post(route('magic-link.request'), ['email' => 'spam@x.test'])->assertRedirect();
    }

    // The 6th within the window is rate-limited.
    $this->post(route('magic-link.request'), ['email' => 'spam@x.test'])->assertStatus(429);
});

it('lets the reserved super-admin email sign in via magic-link (OAuth reservation not weakened)', function () {
    config()->set('larafoundry.security.super_admin.email', 'boss@x.test');

    // Magic-link MUST let the reserved email in (ownership proven by delivery).
    $url = magicLinkUrlFor('boss@x.test');
    $this->get($url)->assertRedirect();

    $admin = User::where('email', 'boss@x.test')->first();
    expect($admin)->not->toBeNull()
        ->and(Auth::id())->toBe($admin->id);

    // And OAuth for that same email is STILL refused — the reservation stands.
    Auth::logout();
    $this->app['auth']->forgetGuards();
    config()->set('larafoundry.auth.oauth.enabled', true);
    config()->set('larafoundry.auth.oauth.providers', ['google']);

    $social = (new SocialiteUser)->setRaw([])->map([
        'id' => 'attacker-gid',
        'nickname' => 'nick',
        'name' => 'Attacker',
        'email' => 'boss@x.test',
        'avatar' => 'https://avatar.test/a.png',
    ]);
    $driver = Mockery::mock();
    $driver->shouldReceive('user')->andReturn($social);
    Socialite::shouldReceive('driver')->with('google')->andReturn($driver);

    $this->get(route('larafoundry.oauth.callback', ['provider' => 'google']))
        ->assertRedirect()
        ->assertSessionHas('error');

    expect(Auth::check())->toBeFalse();
});

it('registers the routes only when enabled and mirrors the flag in the shared prop', function () {
    // Route registration: re-run the auth route file into a fresh router for each
    // flag value (the exact condition the provider evaluates at boot).
    $magicRouteCount = function (bool $enabled): int {
        config()->set('larafoundry.auth.magic_link.enabled', $enabled);

        $router = new Router(app('events'), app());
        Route::swap($router);

        require dirname(__DIR__, 3).'/routes/auth.php';

        return collect($router->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'auth/magic'))
            ->count();
    };

    expect($magicRouteCount(true))->toBe(2)    // request + verify
        ->and($magicRouteCount(false))->toBe(0);

    // Shared prop: resolve the closure the way Inertia does.
    $resolveProp = function (): array {
        $shared = (new HandleInertiaRequests)->share(Request::create('/', 'GET'));
        $value = $shared['auth_magic_link'];

        return is_callable($value) ? $value() : $value;
    };

    config()->set('larafoundry.auth.magic_link.enabled', false);
    expect($resolveProp())->toBe(['enabled' => false]);

    config()->set('larafoundry.auth.magic_link.enabled', true);
    expect($resolveProp())->toBe(['enabled' => true]);
});

it('regenerates the session on login (anti-fixation)', function () {
    $url = magicLinkUrlFor('rotate@x.test');

    $this->withSession(['sentinel' => 'v']);
    $before = session()->getId();

    $this->get($url)->assertRedirect();

    expect(session()->getId())->not->toBe($before);
    expect(Auth::check())->toBeTrue();
});

it('prunes consumed and expired tokens, keeping live ones', function () {
    $live = MagicLoginToken::create([
        'email' => 'live@x.test',
        'token_hash' => hash('sha256', 'a'),
        'expires_at' => now()->addMinutes(15),
        'absolute_expires_at' => now()->addMinutes(30),
    ]);
    $consumed = MagicLoginToken::create([
        'email' => 'used@x.test',
        'token_hash' => hash('sha256', 'b'),
        'expires_at' => now()->addMinutes(15),
        'absolute_expires_at' => now()->addMinutes(30),
        'consumed_at' => now(),
    ]);
    $expired = MagicLoginToken::create([
        'email' => 'old@x.test',
        'token_hash' => hash('sha256', 'c'),
        'expires_at' => now()->subMinute(),
        'absolute_expires_at' => now()->subMinute(),
    ]);

    $this->artisan('larafoundry:magic-links:prune')->assertSuccessful();

    expect(MagicLoginToken::find($live->id))->not->toBeNull()
        ->and(MagicLoginToken::find($consumed->id))->toBeNull()
        ->and(MagicLoginToken::find($expired->id))->toBeNull();
});
