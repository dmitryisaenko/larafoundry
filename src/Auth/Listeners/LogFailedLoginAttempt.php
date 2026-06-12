<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Listeners;

use Dmitryisaenko\LaraFoundry\Auth\Support\AdminAccessFailureContext;
use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Support\Facades\Log;

/**
 * Records failed authentication attempts and raises the admin-access signal.
 *
 * Subscribed to Laravel's auth events (which Fortify's pipeline fires): `Failed`
 * for a single bad-credential attempt, `Lockout` when the throttle trips. Every
 * attempt is logged to the `auth` channel; when the attempt targeted the
 * configured super-admin email it dispatches the unified {@see
 * \Dmitryisaenko\LaraFoundry\Auth\Events\AdminAccessAttemptFailed} signal.
 *
 * This listener no longer mails directly: the core's mail delivery is itself a
 * listener on that event ({@see SendAdminAccessAlertMail}), so password, OTP
 * and PIN failures all flow through one extensible signal and one config gate.
 */
class LogFailedLoginAttempt
{
    public function __construct(
        protected AdminAccessFailureContext $context,
    ) {}

    public function handleFailed(Failed $event): void
    {
        $email = $this->emailFrom($event->credentials);

        Log::channel($this->channel())->warning('Failed login attempt', [
            'email' => $email,
            'guard' => $event->guard,
            'ip' => request()->ip(),
            'user_agent' => request()->userAgent(),
        ]);

        $this->signalIfTargeted($email, 'password');
    }

    public function handleLockout(Lockout $event): void
    {
        $email = $this->emailFrom((array) $event->request->only('email'));

        Log::channel($this->channel())->warning('Login throttled (lockout)', [
            'email' => $email,
            'ip' => $event->request->ip(),
        ]);

        $this->signalIfTargeted($email, 'lockout');
    }

    /**
     * Raise the unified signal when the attempt targeted the super-admin email.
     *
     * Gating here is identity-only (target == super-admin); the master switch,
     * type filter and channel allow-list are applied by the event listeners via
     * AdminAccessAlertPolicy, so every channel agrees on the matrix.
     *
     * @param  'password'|'lockout'  $step
     */
    protected function signalIfTargeted(?string $email, string $step): void
    {
        if (! VisitorStatus::isSuperAdminEmail($email)) {
            return;
        }

        $this->context->dispatch($step, $email);
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
