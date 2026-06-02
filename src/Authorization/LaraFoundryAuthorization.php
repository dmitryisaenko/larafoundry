<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Authorization;

use Dmitryisaenko\LaraFoundry\Tenancy\LaraFoundryTenancy;

/**
 * One-call wiring helper a host invokes for authorization (phase 1.3).
 *
 * Parallel to {@see LaraFoundryTenancy}: exposes
 * the shared Inertia props pages need to drive permission-aware UI. The host merges
 * these into its HandleInertiaRequests::share, so any page can read `$page.props.can`
 * without each controller re-computing it.
 */
class LaraFoundryAuthorization
{
    /**
     * Inertia shared props describing the user's effective permissions in their
     * active company. Lazily evaluated; resolved from the request-memoized set, so
     * sharing it adds no extra queries once a page has already checked a permission.
     *
     * @return array<string, \Closure>
     */
    public static function sharedProps(): array
    {
        return [
            'permissions' => fn () => self::permissionMap(),
        ];
    }

    /**
     * The active user's permission slugs in their active company, as a flat list
     * the frontend can membership-test. Empty for guests / users with no trait.
     *
     * @return list<string>
     */
    protected static function permissionMap(): array
    {
        $user = auth()->user();

        if ($user === null || ! method_exists($user, 'getAllPermissions')) {
            return [];
        }

        $company = method_exists($user, 'getActiveCompany') ? $user->getActiveCompany() : null;

        return $user->getAllPermissions($company)->values()->all();
    }
}
