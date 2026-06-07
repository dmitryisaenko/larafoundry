<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Legal\Support;

use Dmitryisaenko\LaraFoundry\ActivityLog\Facades\Activity;
use Dmitryisaenko\LaraFoundry\Settings\Facades\Settings;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The single authority for consent decisions (phase 5.3 part B).
 *
 * Two concerns, kept in one place so the registration hook, the re-accept gate,
 * the consent controller, the login listener and the Inertia share all agree:
 *
 *  - TERMS — whether the published Terms version (from {@see LegalPageRepository})
 *    must be accepted, what a user has accepted, and recording an acceptance into
 *    the `terms_accepted_*` user settings plus the activity log. FAIL-OPEN: when
 *    no Terms page is published nothing is enforced, so the gate never traps users
 *    behind a page that does not exist.
 *  - COOKIES — whether the banner is enabled, whether a decision has been made
 *    (an encrypted cookie for guests, the `cookie_consent` user setting once
 *    authenticated), and recording a logged-in user's choice.
 *
 * State lives in the generic settings store (decision D-5.3-8: no bespoke consent
 * table); the activity log is the lightweight who/when/which-version proof.
 */
class ConsentManager
{
    public function __construct(
        private readonly LegalPageRepository $pages,
    ) {}

    // --- Terms ---------------------------------------------------------------

    /** Which legal page drives the terms gate. */
    public function termsSlug(): string
    {
        return (string) config('larafoundry-legal.terms.slug', 'terms');
    }

    /**
     * The currently published Terms version, or null when none is published.
     * Null is the fail-open signal the gate and `termsRequired()` read.
     */
    public function currentTermsVersion(): ?int
    {
        return $this->pages->currentVersion($this->termsSlug());
    }

    /** Whether terms enforcement is switched on (the config kill-switch only). */
    public function termsEnforced(): bool
    {
        return (bool) config('larafoundry-legal.terms.enforce', true);
    }

    /**
     * Whether terms must actually be accepted right now: enforcement on AND a
     * Terms page is published. The registration checkbox and the re-accept gate
     * both branch on this, so a host with no published Terms is never blocked.
     */
    public function termsRequired(): bool
    {
        return $this->termsEnforced() && $this->currentTermsVersion() !== null;
    }

    /** The Terms version a user has on record as accepted, or null. */
    public function acceptedTermsVersion(Authenticatable $user): ?string
    {
        $version = Settings::get('terms_accepted_version', $user->getAuthIdentifier());

        return $version === null ? null : (string) $version;
    }

    /**
     * Whether the user must (re-)accept the Terms: required, and what they have on
     * record differs from the published version. Compared as strings because the
     * accepted version is stored as one.
     */
    public function needsTermsAcceptance(Authenticatable $user): bool
    {
        if (! $this->termsRequired()) {
            return false;
        }

        return $this->acceptedTermsVersion($user) !== (string) $this->currentTermsVersion();
    }

    /**
     * Record that a user accepted the Terms: store the version + timestamp and
     * audit it. No-ops when nothing is published (defends a direct accept call).
     * An explicit version pins the accepted value to the one shown to the user.
     */
    public function recordTermsAcceptance(Authenticatable $user, ?int $version = null): void
    {
        $version ??= $this->currentTermsVersion();

        if ($version === null) {
            return;
        }

        $scopeId = $user->getAuthIdentifier();

        Settings::set('terms_accepted_version', (string) $version, $scopeId);
        Settings::set('terms_accepted_at', now()->toDateTimeString(), $scopeId);

        Activity::log(
            description: 'consent.terms_accepted',
            logName: 'default',
            properties: ['version' => $version],
            subject: $user instanceof Model ? $user : null,
            geoSync: false,
        );
    }

    // --- Cookies -------------------------------------------------------------

    public function cookieConsentEnabled(): bool
    {
        return (bool) config('larafoundry-legal.cookie_consent.enabled', false);
    }

    public function cookieName(): string
    {
        return (string) config('larafoundry-legal.cookie_consent.cookie', 'larafoundry_cookie_consent');
    }

    public function cookieLifetimeMinutes(): int
    {
        return (int) config('larafoundry-legal.cookie_consent.lifetime_days', 365) * 24 * 60;
    }

    /**
     * Whether a cookie decision has been made — so the banner can stay dismissed.
     * Authenticated users: the `cookie_consent` setting is the source of truth
     * (D-5.3-6), so we check whether one is stored (not its value — necessary-only
     * is still a decision). Guests: the presence of the encrypted decision cookie.
     */
    public function cookieConsentDecided(Request $request): bool
    {
        $user = $request->user();

        if ($user !== null) {
            return $this->hasRecordedCookieConsent($user);
        }

        return $request->cookie($this->cookieName()) !== null;
    }

    /** Whether the user has a stored cookie-consent decision. */
    public function hasRecordedCookieConsent(Authenticatable $user): bool
    {
        return Settings::has('cookie_consent', $user->getAuthIdentifier());
    }

    /**
     * Persist a logged-in user's cookie choice into settings and audit it. The
     * encrypted browser cookie is queued by the controller; this is the durable,
     * cross-device record that becomes the source of truth once authenticated.
     */
    public function recordCookieConsentForUser(Authenticatable $user, bool $accepted): void
    {
        Settings::set('cookie_consent', $accepted, $user->getAuthIdentifier());

        Activity::log(
            description: 'consent.cookie_updated',
            logName: 'default',
            properties: ['accepted' => $accepted],
            subject: $user instanceof Model ? $user : null,
            geoSync: false,
        );
    }
}
