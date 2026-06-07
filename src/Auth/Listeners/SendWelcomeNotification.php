<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Listeners;

use Dmitryisaenko\LaraFoundry\Auth\Notifications\WelcomeNotification;
use Illuminate\Auth\Events\Verified;

/**
 * Sends the welcome email when a user verifies their address (phase 5.1).
 *
 * Hooks Verified (not Registered, decision D-5.1-13) so the welcome follows
 * successful verification and never races or duplicates the verification mail.
 * The notification is queued, so this listener stays cheap.
 */
class SendWelcomeNotification
{
    public function handle(Verified $event): void
    {
        $user = $event->user;

        // The event's user is whatever model the app verifies; only notify when
        // it is Notifiable (the core's IsLaraFoundryUser trait makes it so).
        if (method_exists($user, 'notify')) {
            $user->notify(new WelcomeNotification);
        }
    }
}
