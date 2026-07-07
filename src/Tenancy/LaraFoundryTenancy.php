<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy;

use Dmitryisaenko\LaraFoundry\Auth\LaraFoundryAuth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One-call wiring helpers a host invokes for tenancy (phase 1.2).
 *
 * Parallel to {@see LaraFoundryAuth}: keeps the
 * host's bootstrap to a single line per concern. Today it exposes the shared
 * Inertia props every tenant-aware page needs (the active company and the
 * switcher list); the host merges these into its HandleInertiaRequests::share.
 */
class LaraFoundryTenancy
{
    /**
     * Inertia shared props describing the user's tenancy state.
     *
     * Returns the active company and the list of companies for the switcher, or
     * nulls/empties for guests and personal mode. Lazily evaluated — the
     * closures only run when a page actually serialises them.
     *
     * @return array<string, \Closure>
     */
    public static function sharedProps(): array
    {
        return [
            'activeCompany' => fn () => self::activeCompany(),
            'companies' => fn () => self::companyList(),
        ];
    }

    /**
     * @return array{id: int|string, uuid: string, name: string, logo_url: string|null, is_owner: bool, is_archived: bool}|null
     */
    protected static function activeCompany(): ?array
    {
        if (config('larafoundry.tenancy.mode') === 'personal') {
            return null;
        }

        $user = auth()->user();

        if ($user === null || ! method_exists($user, 'getActiveCompany')) {
            return null;
        }

        $company = $user->getActiveCompany();

        return $company === null ? null : [
            'id' => $company->getKey(),
            'uuid' => $company->uuid,
            'name' => $company->name,
            'logo_url' => $company->logo_url,
            // Active company is resolved through the user's companies() relation
            // (withPivot includes is_owner), so the pivot is loaded here. Shipped
            // so the shell can show the user's role in the active company without a
            // separate lookup — keeps activeCompany in step with the companies list.
            'is_owner' => (bool) $company->pivot->is_owner,
            // Archived companies are NOT filtered out of the payload: an owner must
            // still see (and be able to unarchive) one. The host decides how to
            // present it — hide it, grey it out — from this flag. Filtering here
            // would strand the owner with no way back in.
            'is_archived' => $company->isArchived(),
        ];
    }

    /**
     * @return array<int, array{id: int|string, uuid: string, name: string, logo_url: string|null, is_owner: bool, is_archived: bool}>
     */
    protected static function companyList(): array
    {
        if (config('larafoundry.tenancy.mode') === 'personal') {
            return [];
        }

        $user = auth()->user();

        if ($user === null || ! method_exists($user, 'companies')) {
            return [];
        }

        return $user->companies()->get()->map(fn ($company) => [
            'id' => $company->getKey(),
            'uuid' => $company->uuid,
            'name' => $company->name,
            'logo_url' => $company->logo_url,
            'is_owner' => (bool) $company->pivot->is_owner,
            // Archived companies stay in the list (see activeCompany) so an owner
            // can reach and unarchive them; the host greys/hides from this flag.
            'is_archived' => $company->isArchived(),
        ])->all();
    }

    /**
     * Render a tenancy Inertia page (helper for the host, parity with auth).
     */
    public static function render(string $page, array $props = []): Response
    {
        return Inertia::render($page, $props);
    }

    /**
     * Resolve the post-setup landing target from config.
     *
     * Accepts either a defined route name or a raw path/URL, so the host points
     * it at its dashboard without the core depending on a host route existing.
     */
    public static function homeUrl(): string
    {
        $target = (string) config('larafoundry.tenancy.home_route', '/');

        if (app('router')->has($target)) {
            return route($target);
        }

        return url($target);
    }
}
