<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Billing\Listeners\AttributeReferral;
use Dmitryisaenko\LaraFoundry\Billing\Models\Affiliate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;

/**
 * Captures a referral click (Ф4.4): GET {prefix}/{code} drops an attribution cookie and
 * redirects to the landing page, so a later sign-up can be credited to the partner.
 *
 * The cookie is encrypted by Laravel's default cookie middleware. The redirect is
 * IDENTICAL whether the code is valid or not — only a real, active partner actually sets
 * a cookie — so the endpoint is not a code-enumeration oracle (and it is throttled at the
 * route level besides). The attribution is only *locked* later, when a company is created
 * (the {@see AttributeReferral} listener).
 */
final class ReferralCaptureController extends Controller
{
    public function __invoke(string $code): RedirectResponse
    {
        $landing = (string) config('larafoundry-billing.affiliate.attribution.landing', '/');
        $redirect = redirect()->to($landing);

        $code = trim($code);

        if ($code === '') {
            return $redirect;
        }

        $affiliate = Affiliate::query()->active()->where('code', $code)->first();

        // Unknown or inactive code: redirect the same way, set nothing.
        if ($affiliate === null) {
            return $redirect;
        }

        $cookieName = (string) config('larafoundry-billing.affiliate.attribution.cookie', 'lf_ref');
        $windowDays = max(1, (int) config('larafoundry-billing.affiliate.attribution.window_days', 60));

        return $redirect->withCookie(cookie($cookieName, $affiliate->code, $windowDays * 24 * 60));
    }
}
