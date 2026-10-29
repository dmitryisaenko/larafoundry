<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Support;

use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;
use Dmitryisaenko\LaraFoundry\Tenancy\LaraFoundryTenancy;
use Dmitryisaenko\LaraFoundry\Tenancy\Support\UserTenant;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves WHO PAYS for the current request, in either tenancy mode.
 *
 * The core supports two tenant shapes (`larafoundry.tenancy.mode`):
 *   - `teams`    — the tenant is a Company; the payer is the user's ACTIVE company;
 *   - `personal` — there are no companies at all
 *     ({@see LaraFoundryTenancy::activeCompany()}
 *     returns null, `BelongsToTenant` isolates rows by `user_id`); the payer is the
 *     signed-in user itself — an ordinary B2C subscription.
 *
 * Before this seam existed the add-on's HTTP layer called `$user->getActiveCompany()`
 * directly, which is permanently null in `personal` mode: the pricing/checkout screens
 * and the blocked page fell over before the request ever reached a gateway. Everything
 * that needs "the paying subject" now goes through here instead, so the two modes stay
 * parallel and no call site has to know which one is active.
 *
 * The gateway drivers were already mode-agnostic — they look the Billable up by
 * `$tenant->getTenantKey()`, which is a company id in `teams` and a user id in
 * `personal` — so `tenant()` below adapts a subject into the core's {@see Tenant}
 * contract for them.
 *
 * NOTE what the subject actually is: the user's active company in `teams` (the host's
 * Company subclass), and the AUTHENTICATED USER itself in `personal` — the auth guard's
 * model, not a lookup through `larafoundry-billing.billable_model`. In `personal` a host
 * must therefore point `billable_model` at that same user class, which is what the
 * README asks for; pointing it elsewhere leaves the payer without the billing traits and
 * the gates fail closed. The add-on hardcodes neither class.
 */
final class BillingSubject
{
    /**
     * Whether the app runs one-tenant-per-user (no companies anywhere).
     */
    public static function isPersonal(): bool
    {
        return config('larafoundry.tenancy.mode') === 'personal';
    }

    /**
     * The paying subject for the authenticated user of the current request.
     */
    public static function current(): ?Model
    {
        return self::for(auth()->user());
    }

    /**
     * The paying subject for the given user: the user itself in `personal` mode, its
     * active company in `teams`.
     *
     * Null for a guest, for a `teams` user with no active company yet, and for a host
     * User that does not expose `getActiveCompany()` — every caller treats null as "no
     * subject", never as "everyone".
     */
    public static function for(?Authenticatable $user): ?Model
    {
        if ($user === null) {
            return null;
        }

        if (self::isPersonal()) {
            // The user IS the tenant here. It still has to be an Eloquent model — the
            // subject carries the subscription columns the gates read.
            return $user instanceof Model ? $user : null;
        }

        if (! method_exists($user, 'getActiveCompany')) {
            return null;
        }

        $company = $user->getActiveCompany();

        return $company instanceof Model ? $company : null;
    }

    /**
     * Adapt a subject into the core's {@see Tenant} contract for the gateway /
     * region / entitlement seams, which are all typed on it.
     *
     * A Company (and any host model that declares `implements Tenant`) is already one
     * and is passed straight through. A plain User model is wrapped in
     * {@see SubjectTenant}, whose tenant key is the user id — exactly what
     * `whereKey($tenant->getTenantKey())` in the Cashier drivers needs — and which
     * still forwards column reads, so entitlements and region routing keep working.
     * A non-Eloquent authenticatable falls back to the core's key-only
     * {@see UserTenant}.
     */
    public static function tenant(?object $subject): ?Tenant
    {
        if ($subject instanceof Tenant) {
            return $subject;
        }

        if ($subject instanceof Model) {
            return new SubjectTenant($subject);
        }

        if ($subject instanceof Authenticatable) {
            return new UserTenant($subject);
        }

        return null;
    }

    /**
     * The TENANT KEY of a subject — the value stored in the add-on's tenant columns
     * (`company_payments.company_id`, `referrals.referred_company_id`, …).
     *
     * A company id in `teams`, a user id in `personal`. Reads `getTenantKey()` when the
     * subject implements the contract (the same value {@see RedeemPromoCodeAction} has
     * always written) and falls back to the primary key otherwise — for an Eloquent User
     * the two are the same id.
     */
    public static function key(?object $subject): int|string|null
    {
        if ($subject instanceof Tenant) {
            return $subject->getTenantKey();
        }

        if ($subject instanceof Model) {
            return $subject->getKey();
        }

        if ($subject instanceof Authenticatable) {
            return $subject->getAuthIdentifier();
        }

        return null;
    }

    /**
     * The words the UI should use for the paying subject, as English-as-key i18n
     * strings the Vue layer renders with `$t()` (the core's frontend convention).
     *
     * A super-admin looking at the payments console in a `personal`-mode product must
     * not read "Company" over a column that holds people's names. The screens take
     * these from the server rather than deciding client-side, because the mode is
     * server state.
     *
     * @return array{title: string, name: string}
     */
    public static function labels(): array
    {
        return self::isPersonal()
            ? ['title' => 'Customer', 'name' => 'Customer name']
            : ['title' => 'Company', 'name' => 'Company name'];
    }

    /**
     * A display name for the subject: the company name in `teams`, the person's name in
     * `personal`. Used by the blocked page and the billing screens so an operator or a
     * customer never reads "Company #17" where a human is the payer.
     */
    public static function label(?object $subject): ?string
    {
        if (! $subject instanceof Model) {
            return null;
        }

        $name = $subject->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : null;
    }
}
