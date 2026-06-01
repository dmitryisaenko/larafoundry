<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Http\Middleware\EnsureEmailIsVerified;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpFoundation\Response;

beforeEach(function () {
    config()->set('larafoundry.security.email_verification.redirect_route', 'verification.notice');
    config()->set('larafoundry.security.email_verification.except_routes', ['verification.*', 'logout']);
    config()->set('larafoundry.security.email_verification.except_prefixes', ['profile']);

    Route::middleware('web')->get('/verify', fn () => 'notice')->name('verification.notice');
});

function verifyNext(): Closure
{
    return fn (Request $request): Response => new Response('app');
}

/** Build a request bound to a named route at a given path. */
function routedRequest(string $path, ?string $routeName = null): Request
{
    $request = Request::create($path, 'GET');
    $route = new Illuminate\Routing\Route(['GET'], $path, []);
    if ($routeName !== null) {
        $route->name($routeName);
    }
    $route->bind($request);
    $request->setRouteResolver(fn () => $route);

    return $request;
}

/** A user model implementing MustVerifyEmail with a toggleable verified state. */
function verifiableUser(bool $verified): Authenticatable&MustVerifyEmail
{
    return new class($verified) implements Authenticatable, MustVerifyEmail
    {
        public function __construct(private bool $verified) {}

        public function hasVerifiedEmail(): bool
        {
            return $this->verified;
        }

        public function getEmailForVerification()
        {
            return 'u@example.com';
        }

        public function markEmailAsVerified(): bool
        {
            return true;
        }

        public function markEmailAsUnverified(): bool
        {
            return true;
        }

        public function sendEmailVerificationNotification(): void {}

        public function getAuthIdentifierName()
        {
            return 'id';
        }

        public function getAuthIdentifier()
        {
            return 1;
        }

        public function getAuthPassword()
        {
            return '';
        }

        public function getAuthPasswordName()
        {
            return 'password';
        }

        public function getRememberToken()
        {
            return '';
        }

        public function setRememberToken($value) {}

        public function getRememberTokenName()
        {
            return '';
        }
    };
}

it('lets guests pass through', function () {
    $request = routedRequest('/dashboard', 'dashboard');

    $response = (new EnsureEmailIsVerified)->handle($request, verifyNext());

    expect($response->getContent())->toBe('app');
});

it('lets verified users pass through', function () {
    $request = routedRequest('/dashboard', 'dashboard');
    $request->setUserResolver(fn () => verifiableUser(verified: true));

    $response = (new EnsureEmailIsVerified)->handle($request, verifyNext());

    expect($response->getContent())->toBe('app');
});

it('redirects unverified users to the verification notice', function () {
    $request = routedRequest('/dashboard', 'dashboard');
    $request->setUserResolver(fn () => verifiableUser(verified: false));

    $response = (new EnsureEmailIsVerified)->handle($request, verifyNext());

    expect($response->isRedirect())->toBeTrue();
    expect($response->headers->get('Location'))->toContain('/verify');
});

it('allows unverified users on an excluded route name', function () {
    $request = routedRequest('/verify', 'verification.notice');
    $request->setUserResolver(fn () => verifiableUser(verified: false));

    $response = (new EnsureEmailIsVerified)->handle($request, verifyNext());

    expect($response->getContent())->toBe('app');
});

it('allows unverified users on an excluded path prefix', function () {
    $request = routedRequest('/profile/settings', 'profile.settings');
    $request->setUserResolver(fn () => verifiableUser(verified: false));

    $response = (new EnsureEmailIsVerified)->handle($request, verifyNext());

    expect($response->getContent())->toBe('app');
});

it('passes through a user model that does not implement MustVerifyEmail', function () {
    $plainUser = new class implements Authenticatable
    {
        public function getAuthIdentifierName()
        {
            return 'id';
        }

        public function getAuthIdentifier()
        {
            return 1;
        }

        public function getAuthPassword()
        {
            return '';
        }

        public function getAuthPasswordName()
        {
            return 'password';
        }

        public function getRememberToken()
        {
            return '';
        }

        public function setRememberToken($value) {}

        public function getRememberTokenName()
        {
            return '';
        }
    };

    $request = routedRequest('/dashboard', 'dashboard');
    $request->setUserResolver(fn () => $plainUser);

    $response = (new EnsureEmailIsVerified)->handle($request, verifyNext());

    expect($response->getContent())->toBe('app');
});
