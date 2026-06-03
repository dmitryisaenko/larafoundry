<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Admin\Http\Controllers;

use Dmitryisaenko\LaraFoundry\ActivityLog\Facades\Activity;
use Dmitryisaenko\LaraFoundry\Admin\Policies\ImpersonationPolicy;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Session-based impersonation — "follow into a user" (phase 2.3).
 *
 * A self-contained implementation (no Lab404 dependency, decision in the plan):
 * `take` stashes the operator's id in the session and logs in as the target;
 * `leave` reverses it. This keeps the core dependency-light and gives full
 * control over the security gate and the audit trail.
 *
 * Security (closes recon finding #1, HIGH):
 *  - every take is authorized by {@see ImpersonationPolicy} (super-admin only,
 *    never another admin, never a blocked/deleted account);
 *  - take is rate-limited per operator;
 *  - BOTH take and leave are written to the activity log (the donor logged
 *    neither).
 *
 * The session key, not the user model, is the source of truth for "currently
 * impersonating" — so a page reload or a new tab stays consistent, and `leave`
 * works even though, while impersonating, the actor is no longer an admin.
 */
class ImpersonateController extends Controller
{
    /**
     * Session key holding the original (operator) user id during impersonation.
     */
    public const SESSION_KEY = 'larafoundry.impersonator_id';

    public function __construct(
        protected ImpersonationPolicy $policy,
    ) {}

    /**
     * Start impersonating the given user.
     */
    public function take(Request $request, int|string $user): RedirectResponse
    {
        $admin = $request->user();

        // Already impersonating? Refuse to nest — leave first. This also stops
        // an impersonated session (not an admin) from starting a new one.
        if ($request->session()->has(self::SESSION_KEY)) {
            abort(403);
        }

        $target = $this->resolveUser($user);

        if ($admin === null || ! $this->policy->take($admin, $target)) {
            abort(403);
        }

        $this->hitRateLimit($admin);

        // Record BEFORE the login swap, while the causer is still the admin.
        Activity::log(
            description: 'admin.impersonate.take',
            logName: 'admin',
            properties: [
                'impersonator_id' => $admin->getAuthIdentifier(),
                'target_id' => $target->getAuthIdentifier(),
            ],
            subject: $target instanceof Model ? $target : null,
            geoSync: false,
        );

        $request->session()->put(self::SESSION_KEY, $admin->getAuthIdentifier());

        Auth::login($target);

        // Rotate the session id on the identity change (defence-in-depth against
        // fixation). regenerate() migrates the session data, so SESSION_KEY set
        // just above survives the rotation.
        $request->session()->regenerate();

        return redirect($this->homeUrl());
    }

    /**
     * Stop impersonating and return to the operator account.
     */
    public function leave(Request $request): RedirectResponse
    {
        $originalId = $request->session()->pull(self::SESSION_KEY);

        if ($originalId === null) {
            // Not impersonating — nothing to leave.
            return redirect($this->homeUrl());
        }

        $impersonated = $request->user();
        $original = $this->findUser($originalId);

        if ($original === null) {
            // The operator account vanished — fail safe: log out entirely.
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect('/');
        }

        Auth::login($original);

        // Rotate the session id back on the identity change too.
        $request->session()->regenerate();

        Activity::log(
            description: 'admin.impersonate.leave',
            logName: 'admin',
            properties: [
                'impersonator_id' => $original->getAuthIdentifier(),
                'target_id' => $impersonated?->getAuthIdentifier(),
            ],
            subject: $impersonated instanceof Model ? $impersonated : null,
            geoSync: false,
        );

        return redirect()->route('admin.users.index');
    }

    /**
     * Throttle take attempts per operator (defence-in-depth on top of the
     * policy): 10 starts per minute. Aborts with 429 when exceeded.
     */
    protected function hitRateLimit(Authenticatable $admin): void
    {
        $key = 'larafoundry:impersonate:'.$admin->getAuthIdentifier();

        if (RateLimiter::tooManyAttempts($key, maxAttempts: 10)) {
            abort(429);
        }

        RateLimiter::hit($key, decaySeconds: 60);
    }

    protected function resolveUser(int|string $id): Authenticatable
    {
        $user = $this->findUser($id);

        if ($user === null) {
            abort(404);
        }

        return $user;
    }

    protected function findUser(int|string $id): ?Authenticatable
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        $user = $model::query()->find($id);

        return $user instanceof Authenticatable ? $user : null;
    }

    /**
     * Where to land after take / fallback. The dashboard is host territory, so
     * the core only knows a route name or path from config (default '/').
     */
    protected function homeUrl(): string
    {
        $home = config('larafoundry.tenancy.home_route', '/');

        if (is_string($home) && app('router')->has($home)) {
            return route($home);
        }

        return is_string($home) && $home !== '' ? $home : '/';
    }
}
