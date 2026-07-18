<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\MagicLink\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Auth\Http\Middleware\TrackSessionActivity;
use Dmitryisaenko\LaraFoundry\Auth\MagicLink\Actions\ConsumeMagicLinkAction;
use Dmitryisaenko\LaraFoundry\Auth\MagicLink\Actions\RequestMagicLinkAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

/**
 * Passwordless email sign-in (magic-link).
 *
 * Two guest routes, registered by the core only while the feature is enabled (a
 * disabled feature 404s, it does not merely hide the UI — mirroring the QR
 * switch):
 *   - {@see Request()}  POST auth/magic/request — mail a one-time signed link.
 *   - {@see verify()}   GET  auth/magic/verify   — click logs in / registers.
 *
 * The heavy lifting lives in the two actions; this controller stays thin. It owns
 * only the HTTP contract: a neutral request response (anti-enumeration), and the
 * login + session-fixation guard on verify (the exact order OAuthController uses).
 */
class MagicLinkController extends Controller
{
    /**
     * Request step: mail a sign-in link for the submitted email.
     *
     * The response is identical whether or not an account owns the address (the
     * action never looks a user up), so it cannot be used to enumerate accounts.
     * Route-level throttling is keyed per (email + IP).
     */
    public function request(Request $request, RequestMagicLinkAction $action): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
        ]);

        $action->handle($validated['email'], $request->ip());

        return back()->with('message-info', __('larafoundry::auth.magic_link.sent'));
    }

    /**
     * Verify step: the `signed` middleware has already proven the link is intact
     * and unexpired; exchange its token for a user and sign them in.
     *
     * A token that is expired, already consumed or unknown resolves to null — a
     * single neutral error, never a reason. On success: log in, regenerate the
     * session (no fixation), drop the method hint for TrackSessionActivity, then
     * land on the intended URL or the configured home.
     */
    public function verify(Request $request, ConsumeMagicLinkAction $action): RedirectResponse
    {
        $user = $action->handle((string) $request->query('token', ''));

        if ($user === null) {
            return redirect($this->loginUrl())
                ->with('message-error', __('larafoundry::auth.magic_link.invalid'));
        }

        Auth::login($user);

        $request->session()->regenerate();
        $request->session()->put(TrackSessionActivity::METHOD_HINT, 'magic_link');

        return redirect()->intended($this->home());
    }

    /**
     * Where an invalid link sends the visitor: the host login screen when Fortify
     * has named it, else the site root (keeps the package self-contained in tests).
     */
    protected function loginUrl(): string
    {
        return Route::has('login') ? route('login') : '/';
    }

    protected function home(): string
    {
        return (string) config('fortify.home', config('larafoundry.tenancy.home_route', '/'));
    }
}
