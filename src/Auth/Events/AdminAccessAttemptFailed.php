<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Events;

use Dmitryisaenko\LaraFoundry\Auth\Support\AdminAccessAlertPolicy;
use Dmitryisaenko\LaraFoundry\Auth\Support\DeviceFingerprint;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A failed attempt to access the platform super-admin account.
 *
 * This is the single domain signal the core raises whenever an authentication
 * step that guards the admin account fails — a bad password, a throttle
 * lockout, a wrong operator-console OTP, or a wrong session PIN — and the
 * target is the super-admin. It is the core's neutral security alert: the
 * core's own mail channel listens to it ({@see
 * \Dmitryisaenko\LaraFoundry\Auth\Listeners\SendAdminAccessAlertMail}), and it
 * is the public EXTENSION POINT for a host to deliver the same alert over any
 * additional channel (Telegram, Slack, etc.) WITHOUT patching the core:
 *
 *     Event::listen(AdminAccessAttemptFailed::class, MyTelegramAlert::class);
 *
 * A host listener should respect the same config gates the core uses by asking
 * {@see AdminAccessAlertPolicy} whether
 * its channel should fire for the given step, so the "which failure type × which
 * channel" matrix stays in one place rather than being duplicated per channel.
 *
 * The event is raised for every targeted-admin failure (a raw signal, useful
 * for auditing); the `notify_admin` / `alert_on` / `channels` config decides
 * which listeners actually react.
 */
class AdminAccessAttemptFailed
{
    use Dispatchable;

    /**
     * @param  'password'|'lockout'|'admin_otp'|'pin'  $step  the auth step that failed
     * @param  string  $ip  the client IP, best-effort
     * @param  string  $userAgent  the raw User-Agent header, best-effort
     * @param  DeviceFingerprint|null  $device  coarse device info (dependency-free), null when unresolved
     * @param  string|null  $email  the targeted admin email when known (password/lockout steps), null otherwise
     */
    public function __construct(
        public readonly string $step,
        public readonly string $ip,
        public readonly string $userAgent,
        public readonly ?DeviceFingerprint $device = null,
        public readonly ?string $email = null,
    ) {}
}
