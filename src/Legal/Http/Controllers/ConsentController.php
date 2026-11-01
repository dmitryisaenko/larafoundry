<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Legal\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Admin\Support\ActingAsSomeoneElse;
use Dmitryisaenko\LaraFoundry\Legal\Http\Middleware\EnsureTermsAccepted;
use Dmitryisaenko\LaraFoundry\Legal\Http\Requests\StoreCookieConsentRequest;
use Dmitryisaenko\LaraFoundry\Legal\Support\ConsentManager;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cookie;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

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
        private readonly ActingAsSomeoneElse $actingAsSomeoneElse,
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

        // An operator signed in as the user decides for their own browser only.
        $user = $request->user();
        if ($user !== null && ! $this->actingAsSomeoneElse->active($request)) {
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
     * is already up to date, there is nothing to accept — send them on. So too an
     * operator signed in as the user: the acceptance is the user's own to give.
     *
     * `first_time`: a person with no accepted version (signed up by Google, or before
     * the Terms were published) is not told the Terms have changed.
     */
    public function showTerms(Request $request): Response|RedirectResponse
    {
        if ($this->actingAsSomeoneElse->active($request) || ! $this->consent->needsTermsAcceptance($request->user())) {
            return redirect()->intended('/');
        }

        return Inertia::render('Legal/Accept', [
            'version' => $this->consent->currentTermsVersion(),
            'terms_url' => route('larafoundry.legal.show', ['slug' => $this->consent->termsSlug()]),
            'first_time' => $this->consent->acceptedTermsVersion($request->user()) === null,
        ]);
    }

    /**
     * Record the current Terms version as accepted and continue to where the user
     * was headed before the gate intercepted them. Refused to an operator signed in
     * as the user: it would stand as the user's own agreement.
     *
     * The screen sends the version it showed: a Terms bumped while it stood open is
     * not what was read, so it goes back to the screen instead of being recorded.
     *
     * A full browser visit, not an Inertia one: where the user was headed may be a
     * page Inertia cannot open (a host's Blade template), which it would show in a
     * modal over the screen.
     */
    public function acceptTerms(Request $request): SymfonyResponse
    {
        abort_if($this->actingAsSomeoneElse->active($request), 403);

        if ($request->filled('version') && $request->integer('version') !== $this->consent->currentTermsVersion()) {
            return redirect()->route('larafoundry.consent.terms.show');
        }

        $this->consent->recordTermsAcceptance($request->user());

        $request->session()->flash('status', __('larafoundry::legal.terms.accepted'));

        $redirect = redirect()->intended('/');

        // What Inertia::location() answers, without depending on the request macro.
        return $request->headers->has('X-Inertia')
            ? response('', 409, ['X-Inertia-Location' => $redirect->getTargetUrl()])
            : $redirect;
    }
}
