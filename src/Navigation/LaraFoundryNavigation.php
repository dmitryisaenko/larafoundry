<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Navigation;

use Dmitryisaenko\LaraFoundry\Admin\Http\Controllers\ImpersonateController;
use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Dmitryisaenko\LaraFoundry\Navigation\Support\MenuBuilder;

/**
 * One-call wiring helpers a host invokes for navigation (phase 2.3).
 *
 * Parallel to LaraFoundryTenancy / LaraFoundryAuthorization: the host merges
 * {@see sharedProps()} into its `HandleInertiaRequests::share`, and every page
 * receives the already-filtered menu tree plus the visitor status the
 * LayoutSwitcher needs to choose a shell (decision D-nav-d).
 *
 * Decision D-nav-a: the menu shipped here is built AND filtered on the backend,
 * so links the user may not see never reach the client.
 */
class LaraFoundryNavigation
{
    /**
     * Inertia shared props: the filtered menu for the user's zone, the
     * navigation level the LayoutSwitcher keys off, the visitor status and the
     * impersonation flag.
     *
     * Lazily evaluated — the closures run only when a page serialises them.
     *
     * `nav_level` is the single source of truth for shell selection: the
     * frontend LayoutSwitcher chooses its layout from THIS prop (which is the
     * same value that built the menu), not from a sibling module's
     * `activeCompany` prop — so the menu and the shell can never disagree, and a
     * host that wires navigation but not tenancy props still gets the right shell.
     *
     * @return array<string, \Closure>
     */
    public static function sharedProps(): array
    {
        return [
            'navigation' => fn () => self::menu(),
            'nav_level' => fn () => self::levelFor(auth()->user()),
            'visitor_status' => fn () => app(VisitorStatus::class)->for(),
            'impersonating' => fn () => self::impersonating(),
        ];
    }

    /**
     * Build the menu for the current visitor's navigation level.
     *
     * Admins get the operator-console menu; an authenticated user with an active
     * company gets the tenant menu; everyone else (guest, no company yet) gets
     * nothing. The builder filters by RBAC, so an empty array is a valid answer.
     *
     * @return array<int, array<string, mixed>>
     */
    protected static function menu(): array
    {
        $user = auth()->user();

        $level = self::levelFor($user);

        if ($level === null) {
            return [];
        }

        return app(MenuBuilder::class)->build($level, $user);
    }

    /**
     * Whether the current session is impersonating another user (phase 2.3) —
     * drives the "you are impersonating X" banner. The session key is the source
     * of truth (set by ImpersonateController::take).
     */
    protected static function impersonating(): bool
    {
        $session = request()->hasSession() ? request()->session() : null;

        return $session !== null && $session->has(ImpersonateController::SESSION_KEY);
    }

    /**
     * The navigation level for a user: 'admin' for super-admins, 'tenant' for a
     * company member with an active company, else null (guest / no company yet).
     *
     * Both the `navigation` and `nav_level` props call this; it stays cheap —
     * `isAdmin` is a flag plus one config read, and `getActiveCompany` is memoised
     * per request by the tenant resolver — so it is not separately cached here
     * (a static memo would risk leaking across requests under a persistent worker).
     */
    protected static function levelFor(?object $user): ?string
    {
        if ($user === null) {
            return null;
        }

        if (app(VisitorStatus::class)->isAdmin($user)) {
            return 'admin';
        }

        if (method_exists($user, 'getActiveCompany') && $user->getActiveCompany() !== null) {
            return 'tenant';
        }

        return null;
    }
}
