<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Listeners;

use Dmitryisaenko\LaraFoundry\Auth\Support\AdminAccessFailureContext;
use Dmitryisaenko\LaraFoundry\Auth\Support\VisitorStatus;
use Laravel\Fortify\Events\TwoFactorAuthenticationFailed;

/**
 * Raises {@see AdminAccessAttemptFailed} when the operator-console OTP step-up
 * fails for the super-admin.
 *
 * Fortify fires {@see TwoFactorAuthenticationFailed} for EVERY user's login-2FA
 * failure, not only the admin console — so this listener gates strictly on the
 * super-admin identity ({@see VisitorStatus::isAdmin}) and stays silent for a
 * normal user's 2FA miss. The raw signal is always raised for a super-admin
 * failure; the `notify_admin` / `alert_on` / `channels` config decides who
 * reacts (the core mail listener and any host channel listeners).
 */
class AlertOnAdminOtpFailure
{
    public function __construct(
        protected VisitorStatus $visitor,
        protected AdminAccessFailureContext $context,
    ) {}

    public function handle(TwoFactorAuthenticationFailed $event): void
    {
        if (! $this->visitor->isAdmin($event->user)) {
            return;
        }

        $this->context->dispatch('admin_otp', $this->emailOf($event->user));
    }

    protected function emailOf(mixed $user): ?string
    {
        if (is_object($user) && method_exists($user, 'getAttribute')) {
            $email = $user->getAttribute('email');

            return is_string($email) ? $email : null;
        }

        return null;
    }
}
