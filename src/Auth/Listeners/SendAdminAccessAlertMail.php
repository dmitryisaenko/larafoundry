<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Listeners;

use Dmitryisaenko\LaraFoundry\Auth\Events\AdminAccessAttemptFailed;
use Dmitryisaenko\LaraFoundry\Auth\Notifications\AdminLoginAttemptNotification;
use Dmitryisaenko\LaraFoundry\Auth\Support\AdminAccessAlertPolicy;
use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Illuminate\Support\Facades\Notification;

/**
 * The core's neutral default channel for {@see AdminAccessAttemptFailed}: an
 * email to the configured admin address.
 *
 * It is just one listener among (potentially) many — a host adds its own
 * listeners for extra channels (Telegram, Slack, ...) on the same event. Like
 * any channel listener it defers to {@see AdminAccessAlertPolicy} so the
 * master switch (`notify_admin`), the failure-type filter (`alert_on`) and the
 * channel allow-list (`channels` must contain 'mail') all apply uniformly.
 *
 * The notification class can be swapped by a host through
 * `larafoundry.auth.failed_login.notification` (FQCN), defaulting to the core's
 * {@see AdminLoginAttemptNotification} (decision D-3 override-seam).
 */
class SendAdminAccessAlertMail
{
    public function handle(AdminAccessAttemptFailed $event): void
    {
        if (! AdminAccessAlertPolicy::shouldAlert($event->step, 'mail')) {
            return;
        }

        // Resolve the recipient through the same accessor that the identity gate
        // ({@see LogFailedLoginAttempt}) uses to decide whose attempt this is:
        // VisitorStatus::superAdminEmail() returns the canonical
        // `security.super_admin.email`, falling back to the legacy
        // `auth.failed_login.admin_email`. Reading the raw admin_email here would
        // silently drop the alert whenever only the super-admin email is set —
        // exactly the case the gate still fires on.
        $adminEmail = VisitorStatus::superAdminEmail();

        if (! is_string($adminEmail) || $adminEmail === '') {
            return;
        }

        Notification::route('mail', $adminEmail)
            ->notify($this->makeNotification($event));
    }

    /**
     * Build the mail notification, honouring a host-supplied override class.
     */
    protected function makeNotification(AdminAccessAttemptFailed $event): object
    {
        $class = config(
            'larafoundry.auth.failed_login.notification',
            AdminLoginAttemptNotification::class,
        );

        if (! is_string($class) || ! is_a($class, AdminLoginAttemptNotification::class, true)) {
            $class = AdminLoginAttemptNotification::class;
        }

        return new $class(
            step: $event->step,
            ip: $event->ip,
            userAgent: $event->userAgent,
            device: $event->device,
        );
    }
}
