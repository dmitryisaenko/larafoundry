<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Listeners;

use Dmitryisaenko\LaraFoundry\Auth\Notifications\AdminLoginAttemptNotification;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Records failed authentication attempts and optionally alerts an admin.
 *
 * Subscribed to Laravel's auth events (which Fortify's pipeline fires): `Failed`
 * for a single bad-credential attempt, `Lockout` when the throttle trips. Every
 * attempt is logged to the `auth` channel; an admin notification is sent only
 * when the attempt targeted the configured admin email — mirroring the donor's
 * "someone is trying to get into the admin account" alert, without the donor's
 * hard-coded Telegram token.
 */
class LogFailedLoginAttempt
{
    public function handleFailed(Failed $event): void
    {
        $email = $this->emailFrom($event->credentials);

        Log::channel($this->channel())->warning('Failed login attempt', [
            'email' => $email,
            'guard' => $event->guard,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        $this->notifyAdminIfTargeted($email, 'failed');
    }

    public function handleLockout(Lockout $event): void
    {
        $email = $this->emailFrom((array) $event->request->only('email'));

        Log::channel($this->channel())->warning('Login throttled (lockout)', [
            'email' => $email,
            'ip' => $event->request->ip(),
        ]);

        $this->notifyAdminIfTargeted($email, 'lockout');
    }

    protected function notifyAdminIfTargeted(?string $email, string $step): void
    {
        if (! config('larafoundry.auth.failed_login.notify_admin', false)) {
            return;
        }

        $adminEmail = config('larafoundry.auth.failed_login.admin_email');

        if (! is_string($adminEmail) || $adminEmail === '' || $email !== $adminEmail) {
            return;
        }

        Notification::route('mail', $adminEmail)
            ->notify(new AdminLoginAttemptNotification(
                step: $step,
                ip: (string) request()->ip(),
                userAgent: (string) request()->userAgent(),
            ));
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    protected function emailFrom(array $credentials): ?string
    {
        $email = $credentials['email'] ?? null;

        return is_string($email) ? $email : null;
    }

    protected function channel(): string
    {
        return config('logging.default', 'stack');
    }
}
