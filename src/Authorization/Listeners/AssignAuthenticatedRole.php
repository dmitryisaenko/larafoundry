<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Authorization\Listeners;

use Dmitryisaenko\LaraFoundry\Authorization\Models\Role;
use Illuminate\Auth\Events\Registered;

/**
 * Give every newly registered user the global `authenticated` role (phase 1.3).
 *
 * Listens on Registered, which fires for both native (Fortify) and OAuth sign-ups,
 * so the base role lands on every path. assignRole is idempotent.
 *
 * Defensive: if the `authenticated` role does not exist yet (the catalog was never
 * synced), it skips silently rather than failing registration — a missing base
 * role must never block a user from signing up. Run `larafoundry:permissions:sync`
 * as part of install so the role is present.
 */
class AssignAuthenticatedRole
{
    public function handle(Registered $event): void
    {
        $user = $event->user;

        if (! method_exists($user, 'assignRole')) {
            return;
        }

        $role = Role::query()
            ->where('slug', 'authenticated')
            ->where('is_global', true)
            ->first();

        if ($role === null) {
            return;
        }

        $user->assignRole($role);
    }
}
