<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Listeners;

use Dmitryisaenko\LaraFoundry\Billing\Http\Controllers\ReferralCaptureController;
use Dmitryisaenko\LaraFoundry\Billing\Models\Affiliate;
use Dmitryisaenko\LaraFoundry\Billing\Models\Referral;
use Dmitryisaenko\LaraFoundry\Billing\Support\BillingSubject;
use Dmitryisaenko\LaraFoundry\Tenancy\Events\CompanyCreated;
use Illuminate\Auth\Events\Registered;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cookie;

/**
 * Locks a referral attribution when a new tenant appears (Ф4.4).
 *
 * Which moment that is depends on the tenancy mode, so the listener has two entry
 * points and each is inert in the other mode:
 *  - `teams`    — {@see handle()} on the CORE's {@see CompanyCreated}: the referred
 *    tenant is the new company;
 *  - `personal` — {@see handleRegistration()} on {@see Registered}: there are no
 *    companies at all, so the referred tenant is the user who just signed up. Without
 *    this branch a partner link could never earn anything in a B2C product.
 *
 * Either way the add-on plugs into a seam that already fires — attribution needs ZERO
 * change to the core or the host. The attribution cookie was dropped earlier by
 * {@see ReferralCaptureController}; Laravel's cookie middleware has already decrypted
 * it by the time this runs.
 *
 * Guards (each fails closed — no row written):
 *  - no/blank cookie, or no matching ACTIVE affiliate;
 *  - self-referral (the partner is the new tenant's owner, or is the new user itself) —
 *    never credit yourself;
 *  - the tenant is already attributed (lock-once; the unique index is the race backstop).
 *
 * The cookie is forgotten on every path that consumed it, so one click attributes one
 * tenant, not every tenant the visitor later creates.
 */
final class AttributeReferral
{
    public function __construct(private readonly Request $request) {}

    public function handle(CompanyCreated $event): void
    {
        // In `personal` mode companies are not the paying tenant; the registration
        // hook below owns attribution there.
        if (BillingSubject::isPersonal()) {
            return;
        }

        $this->attribute(
            BillingSubject::key($event->company),
            $event->owner->getAuthIdentifier(),
        );
    }

    /**
     * `personal` mode: the user who just registered IS the tenant, so their own id is
     * both the referred key and the self-referral guard.
     */
    public function handleRegistration(Registered $event): void
    {
        if (! BillingSubject::isPersonal()) {
            return;
        }

        $user = $event->user;

        if (! $user instanceof Authenticatable) {
            return;
        }

        $ownerId = $user->getAuthIdentifier();

        // Only a genuine self-registration is attributable. A host that CREATES users
        // from an admin screen, an invite or an importer also fires Registered — inside
        // the operator's request, whose browser may well carry an `lf_ref` cookie. That
        // would credit a partner for accounts they never referred. During a real
        // sign-up either nobody is authenticated yet (Fortify fires the event before it
        // logs the user in) or it is already the new user.
        $current = $this->request->user();

        if ($current !== null && (string) $current->getAuthIdentifier() !== (string) $ownerId) {
            return;
        }

        $this->attribute($ownerId, $ownerId);
    }

    /**
     * Write the attribution for a referred tenant key, unless a guard refuses it.
     *
     * `$ownerId` is whoever would be crediting themselves: the company's founder in
     * `teams`, the new user in `personal`.
     */
    private function attribute(int|string|null $referredKey, mixed $ownerId): void
    {
        if ($referredKey === null) {
            return;
        }

        $cookieName = (string) config('larafoundry-billing.affiliate.attribution.cookie', 'lf_ref');
        $code = $this->request->cookie($cookieName);

        if (! is_string($code) || $code === '') {
            return;
        }

        $affiliate = Affiliate::query()->active()->where('code', $code)->first();

        if ($affiliate === null) {
            return;
        }

        // Self-referral guard: a partner cannot earn from a tenant they own. Compared as
        // strings, not ints — a host on uuid/ulid user keys would cast BOTH sides to 0
        // and refuse every attribution.
        if ((string) $affiliate->user_id === (string) $ownerId) {
            $this->forget($cookieName);

            return;
        }

        // Lock-once: firstOrCreate against the unique referred_company_id never re-points
        // an existing attribution to a new partner.
        Referral::query()->firstOrCreate(
            ['referred_company_id' => $referredKey],
            [
                'affiliate_id' => $affiliate->getKey(),
                'source_code' => $affiliate->code,
                'attributed_at' => Carbon::now(),
                'window_ends_at' => $this->windowEndsAt(),
            ],
        );

        $this->forget($cookieName);
    }

    /**
     * The recurring-commission window end, or null when the trigger does not bound by
     * date (first_payment / lifetime).
     */
    private function windowEndsAt(): ?Carbon
    {
        if (config('larafoundry-billing.affiliate.commission.trigger') !== 'recurring') {
            return null;
        }

        $months = max(1, (int) config('larafoundry-billing.affiliate.commission.window_months', 12));

        return Carbon::now()->addMonths($months);
    }

    private function forget(string $cookieName): void
    {
        Cookie::queue(Cookie::forget($cookieName));
    }
}
