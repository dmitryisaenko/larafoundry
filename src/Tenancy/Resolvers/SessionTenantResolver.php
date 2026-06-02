<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Resolvers;

use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\Tenant;
use Dmitryisaenko\LaraFoundry\Tenancy\Contracts\TenantResolver;
use Dmitryisaenko\LaraFoundry\Tenancy\Models\Company;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;

/**
 * `teams`-mode resolver: the active tenant is a Company remembered per device.
 *
 * Mirrors the legacy donor's storage exactly — the active company id lives on
 * the tracked `user_sessions` row (keyed to the live session id), with a session
 * key as a fallback for the brief window before the row exists. Per-device is
 * the point: two browser tabs / two phones can act as two different companies.
 *
 * Crucially, `current()` only returns a company the user STILL belongs to (it
 * resolves through the user's `companies()` relation, which excludes removed
 * memberships and soft-deleted companies). A stale or revoked id resolves to
 * null, and the scope then fails closed.
 */
class SessionTenantResolver implements TenantResolver
{
    /**
     * Session key holding the active company id (fallback + legacy parity).
     */
    public const SESSION_KEY = 'larafoundry.active_company_id';

    /**
     * Per-request memo of the resolved tenant, keyed by user id.
     *
     * The resolver is bound `scoped()` (one instance per request), and the
     * global scope calls current() on EVERY query to a tenant-scoped model.
     * Without this, each such query would re-run two DB reads (the session row +
     * the membership find). Memoising collapses them to once per request. Cleared
     * on setCurrent/forget so a switch within the request is seen immediately.
     *
     * @var array<int|string, Tenant|null>
     */
    protected array $resolved = [];

    /**
     * The current request, resolved lazily on each access.
     *
     * The resolver is bound `scoped()` (one instance per request), and injecting
     * the Request through the constructor would freeze whichever Request object
     * existed when the resolver was first built — which, depending on resolution
     * order, can be a different instance from the one the session is later
     * attached to, so session writes would vanish. Reading `request()` at call
     * time always hits the live request and its started session.
     */
    protected function request(): Request
    {
        return request();
    }

    public function current(Authenticatable $user): ?Tenant
    {
        if (! $this->canCarryCompanies($user)) {
            return null;
        }

        $cacheKey = $user->getAuthIdentifier();

        if (array_key_exists($cacheKey, $this->resolved)) {
            return $this->resolved[$cacheKey];
        }

        $companyId = $this->activeCompanyId($user);

        if ($companyId === null) {
            return $this->resolved[$cacheKey] = null;
        }

        // Resolve through the membership relation so a company the user no longer
        // belongs to (or that was soft-deleted) yields null — fail-closed input.
        /** @var Company|null $company */
        $company = $user->companies()->find($companyId);

        return $this->resolved[$cacheKey] = $company;
    }

    public function setCurrent(Authenticatable $user, Tenant|int|string|null $tenant): void
    {
        $companyId = $tenant instanceof Tenant ? $tenant->getTenantKey() : $tenant;

        // Session-safe: when a company is created from a queued job or console
        // command there is no request session and no tracked-session row to bind
        // to, so this is a no-op there — the active company simply isn't recorded
        // (nothing is acting "as" it outside a web session). On the user's next
        // web request SetActiveTenant re-selects their owned company. Inside a web
        // request, both the session key and the tracked row are written.
        if ($this->request()->hasSession()) {
            $this->request()->session()->put(self::SESSION_KEY, $companyId);
        }

        $this->writeToTrackedSession($user, $companyId);
        unset($this->resolved[$user->getAuthIdentifier()]);
    }

    public function forget(Authenticatable $user): void
    {
        if ($this->request()->hasSession()) {
            $this->request()->session()->forget(self::SESSION_KEY);
        }

        $this->writeToTrackedSession($user, null);
        unset($this->resolved[$user->getAuthIdentifier()]);
    }

    /**
     * The active company id from the tracked session row, falling back to the
     * raw session key (covers the first request before the row is written).
     */
    protected function activeCompanyId(Authenticatable $user): int|string|null
    {
        if (! $this->request()->hasSession()) {
            return null;
        }

        $sessionId = $this->request()->session()->getId();

        $tracked = $user->sessions()
            ->where('session_id', $sessionId)
            ->value('active_company_id');

        if ($tracked !== null) {
            return $tracked;
        }

        return $this->request()->session()->get(self::SESSION_KEY);
    }

    /**
     * Persist the active company id onto the current tracked session row.
     *
     * The row is created by TrackSessionActivity (phase 1.1) AFTER the response,
     * so on the very first request it may not exist yet — the session-key
     * fallback covers that gap until the next request reconciles.
     */
    protected function writeToTrackedSession(Authenticatable $user, int|string|null $companyId): void
    {
        if (! $this->request()->hasSession()) {
            return;
        }

        $user->sessions()
            ->where('session_id', $this->request()->session()->getId())
            ->update(['active_company_id' => $companyId]);
    }

    /**
     * Whether this authenticatable exposes the tenancy relation/behaviour.
     *
     * Guards against a host User that hasn't applied BelongsToTenancy — better a
     * null tenant (fail-closed) than a fatal on a missing relation.
     */
    protected function canCarryCompanies(Authenticatable $user): bool
    {
        return method_exists($user, 'companies')
            && method_exists($user, 'sessions')
            && $user->companies() instanceof BelongsToMany;
    }
}
