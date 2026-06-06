<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Auth\Http\Middleware\TrackSessionActivity;
use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Contracts\User as SocialiteUser;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * Social (OAuth) sign-in via Laravel Socialite.
 *
 * Fortify does not cover OAuth, so this is core-owned. The flow has two routes:
 * `redirect` hands off to the provider, `callback` receives the verified
 * profile and resolves it to a local user.
 *
 * Session tracking is NOT done here: the TrackSessionActivity middleware records
 * it per request. Before logging in we drop a method hint into the session so
 * that middleware records the provider name (not 'native') as the login method.
 *
 * SECURITY — account-takeover guard. The legacy donor did
 * `User::updateOrCreate(['email' => $social->email], [...])`, which silently
 * binds any OAuth login to a pre-existing LOCAL account sharing that email.
 * An attacker who controls a provider account asserting the victim's email
 * could thereby seize the victim's local account. Here we resolve strictly:
 *
 *   1. existing link (provider_name + provider_id) → that user, always;
 *   2. else an email match to a local account → linked ONLY when
 *      `larafoundry.auth.oauth.link_existing` is true; otherwise refused with a
 *      message telling the user to sign in locally and link from settings;
 *   3. else → a brand-new account, with email pre-verified by the provider.
 */
class OAuthController extends Controller
{
    public function redirect(string $provider, Request $request): RedirectResponse
    {
        if (! $this->providerAllowed($provider)) {
            return $this->fail(__('larafoundry::auth.oauth.invalid_provider'));
        }

        $request->session()->put('oauth_remember', $request->boolean('remember'));

        try {
            return Socialite::driver($provider)->redirect();
        } catch (Throwable) {
            return $this->fail(__('larafoundry::auth.oauth.redirect_failed'));
        }
    }

    public function callback(string $provider, Request $request): RedirectResponse
    {
        // The remember choice is consumed regardless of how the callback ends,
        // so a refused/failed attempt never leaks the flag into the next one.
        $remember = (bool) $request->session()->pull('oauth_remember', false);

        if (! $this->providerAllowed($provider)) {
            return $this->fail(__('larafoundry::auth.oauth.invalid_provider'));
        }

        try {
            $socialUser = Socialite::driver($provider)->user();
        } catch (Throwable) {
            return $this->fail(__('larafoundry::auth.oauth.callback_failed'));
        }

        if (! is_string($socialUser->getEmail()) || $socialUser->getEmail() === '') {
            return $this->fail(__('larafoundry::auth.oauth.no_email'));
        }

        $resolution = $this->resolveUser($provider, $socialUser);

        if ($resolution['status'] === 'refused') {
            return $this->fail(__('larafoundry::auth.oauth.email_taken'));
        }

        Auth::login($resolution['user'], $remember);

        // Regenerate first (no session fixation), then leave the provider hint on
        // the fresh session for TrackSessionActivity to pick up this request.
        $request->session()->regenerate();
        $request->session()->put(TrackSessionActivity::METHOD_HINT, $provider);

        return redirect()->intended(config('larafoundry.auth.oauth.redirect_after_login', '/'));
    }

    /**
     * @return array{status: 'ok'|'refused', user: mixed}
     */
    protected function resolveUser(string $provider, SocialiteUser $social): array
    {
        $model = $this->userModel();

        // 1. Already linked by provider identity — the only unconditional match.
        $linked = $model::query()
            ->where('provider_name', $provider)
            ->where('provider_id', $social->getId())
            ->first();

        if ($linked !== null) {
            $linked->forceFill([
                'provider_token' => $social->token ?? null,
                'provider_refresh_token' => $social->refreshToken ?? null,
            ])->save();

            return ['status' => 'ok', 'user' => $linked];
        }

        // The super-admin email is reserved (phase 1.4): OAuth must not bind it
        // to a NEW or an existing local account. Branch 1 above still lets an
        // already-linked operator sign in; this only blocks claiming or linking
        // the reserved email fresh — closing the takeover/squatting vector that
        // bypasses registration's reservation. Refused generically (same message
        // as email-taken) so it does not reveal that the email is the admin's.
        if (VisitorStatus::isSuperAdminEmail($social->getEmail())) {
            return ['status' => 'refused', 'user' => null];
        }

        // 2. A local account already owns this email.
        $existing = $model::query()->where('email', $social->getEmail())->first();

        if ($existing !== null) {
            if (! config('larafoundry.auth.oauth.link_existing', false)) {
                return ['status' => 'refused', 'user' => null];
            }

            $existing->forceFill($this->providerAttributes($provider, $social))->save();

            return ['status' => 'ok', 'user' => $existing];
        }

        // 3. New account, pre-verified by the provider.
        $user = $model::query()->create(array_merge(
            [
                'name' => $social->getName() ?: $social->getNickname() ?: $social->getEmail(),
                'email' => $social->getEmail(),
                'email_verified_at' => now(),
                'avatar' => $social->getAvatar(),
                'locale' => config('larafoundry.locale.default', 'en'),
            ],
            $this->providerAttributes($provider, $social),
        ));

        return ['status' => 'ok', 'user' => $user];
    }

    /**
     * @return array<string, mixed>
     */
    protected function providerAttributes(string $provider, SocialiteUser $social): array
    {
        return [
            'provider_name' => $provider,
            'provider_id' => $social->getId(),
            'provider_token' => $social->token ?? null,
            'provider_refresh_token' => $social->refreshToken ?? null,
        ];
    }

    protected function providerAllowed(string $provider): bool
    {
        return config('larafoundry.auth.oauth.enabled', false)
            && in_array($provider, (array) config('larafoundry.auth.oauth.providers', []), true);
    }

    /**
     * @return class-string<Model>
     */
    protected function userModel(): string
    {
        return config('auth.providers.users.model', User::class);
    }

    protected function fail(string $message): RedirectResponse
    {
        return redirect('/')->with('error', $message);
    }
}
