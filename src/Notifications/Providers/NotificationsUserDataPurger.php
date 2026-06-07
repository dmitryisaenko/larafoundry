<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Notifications\Providers;

use Dmitryisaenko\LaraFoundry\Notifications\Console\Commands\PruneNotificationsCommand;
use Dmitryisaenko\LaraFoundry\Profile\Contracts\PurgesUserData;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;

/**
 * Erases the user's in-app notifications on account deletion (phase 5.3).
 *
 * The notifications module owns this section, added to the purge registry from
 * bootNotifications() — the symmetric mirror of its export provider. Only the
 * user's OWN inbox rows are removed (the pivot scoped to them); the shared
 * Notification broadcasts they were detached from are swept up later by
 * {@see PruneNotificationsCommand}
 * once no recipients remain, so a broadcast's PII payload never lingers.
 */
class NotificationsUserDataPurger implements PurgesUserData
{
    public function purgeFor(Authenticatable $user): void
    {
        if (! method_exists($user, 'appNotifications')) {
            return;
        }

        DB::table('larafoundry_notification_user')
            ->where('user_id', $user->getAuthIdentifier())
            ->delete();
    }

    public function key(): string
    {
        return 'notifications';
    }

    public function priority(): int
    {
        return 50;
    }
}
