<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Listeners;

use Dmitryisaenko\LaraFoundry\Admin\Support\ActingAsSomeoneElse;
use Dmitryisaenko\LaraFoundry\Legal\Support\ConsentManager;
use Illuminate\Auth\Events\Login;

/**
 * Carries a guest's cookie-consent decision into their account on login (phase
 * 5.3 part B, decision D-5.3-6).
 *
 * A guest's choice lives in an encrypted cookie; once they authenticate the
 * `cookie_consent` user setting becomes the source of truth. This adopts the
 * cookie choice ONLY on first login (when nothing is recorded yet) — a user who
 * has already decided keeps their stored preference, so a stale cookie on a shared
 * machine cannot silently flip it.
 *
 * Not when an operator signs in as the user: the cookie is the operator's browser's
 * (a way of signing in as someone has to set its mark BEFORE the login to be seen).
 */
class SyncCookieConsentOnLogin
{
    public function __construct(
        protected ConsentManager $consent,
        protected ActingAsSomeoneElse $actingAsSomeoneElse,
    ) {}

    public function handle(Login $event): void
    {
        $user = $event->user;

        if ($this->actingAsSomeoneElse->active(request())) {
            return;
        }

        if ($this->consent->hasRecordedCookieConsent($user)) {
            return;
        }

        $cookie = request()->cookie($this->consent->cookieName());

        if ($cookie === null) {
            return;
        }

        $this->consent->recordCookieConsentForUser($user, $cookie === 'accept');
    }
}
