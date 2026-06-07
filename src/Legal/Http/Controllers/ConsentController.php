<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Legal\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Legal\Http\Middleware\EnsureTermsAccepted;
use Dmitryisaenko\LaraFoundry\Legal\Http\Requests\StoreCookieConsentRequest;
use Dmitryisaenko\LaraFoundry\Legal\Support\ConsentManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Records consent decisions (phase 5.3 part B): the cookie banner and the Terms
 * re-acceptance screen.
 *
 * The cookie endpoint is open to guests and authenticated users alike; the Terms
 * endpoints sit behind `auth` (routes/consent.php). The Terms re-accept screen is
 * where {@see EnsureTermsAccepted}
 * sends a user whose accepted version is stale — it must stay reachable while the
 * gate is active, so the gate excludes these routes (no redirect loop).
 */
class ConsentController extends Controller
{
    public function __construct(
        private readonly ConsentManager $consent,
    ) {}

    /**
     * Store a cookie-consent decision. Always drops an encrypted decision cookie
     * (so the banner stays dismissed for guests and pre-login); for an
     * authenticated user it also writes the durable `cookie_consent` setting, the
     * source of truth once signed in.
     */
    public function storeCookie(StoreCookieConsentRequest $request): RedirectResponse
    {
        $decision = (string) $request->validated()['decision'];
        $accepted = $decision === 'accept';

        $user = $request->user();
        if ($user !== null) {
            $this->consent->recordCookieConsentForUser($user, $accepted);
        }

        Cookie::queue(
            $this->consent->cookieName(),
            $decision,
            $this->consent->cookieLifetimeMinutes(),
        );

        return back();
    }

    /**
     * The Terms re-acceptance screen. If nothing is enforced/published or the user
     * is already up to date, there is nothing to accept — send them on.
     */
    public function showTerms(Request $request): Response|RedirectResponse
    {
        if (! $this->consent->needsTermsAcceptance($request->user())) {
            return redirect()->intended('/');
        }

        return Inertia::render('Legal/Accept', [
            'version' => $this->consent->currentTermsVersion(),
            'terms_url' => route('larafoundry.legal.show', ['slug' => $this->consent->termsSlug()]),
        ]);
    }

    /**
     * Record the current Terms version as accepted and continue to where the user
     * was headed before the gate intercepted them.
     */
    public function acceptTerms(Request $request): RedirectResponse
    {
        $this->consent->recordTermsAcceptance($request->user());

        return redirect()->intended('/')->with('status', __('larafoundry::legal.terms.accepted'));
    }
}
