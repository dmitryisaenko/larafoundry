<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Notifications;

use Dmitryisaenko\LaraFoundry\Auth\Support\DeviceFingerprint;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Alerts an admin that someone attempted (and failed) to access the admin
 * account (a bad password, lockout, operator OTP, or session PIN).
 *
 * Mail-only and localised through `larafoundry::auth` so the host can override
 * the wording. The donor also routed this to Telegram with a hard-coded bot
 * token; that is intentionally dropped — host-specific channels belong in the
 * host's own listener on {@see
 * \Dmitryisaenko\LaraFoundry\Auth\Events\AdminAccessAttemptFailed}, not the
 * core. A host that only wants to reword/restructure the mail can swap this
 * class via `larafoundry.auth.failed_login.notification`.
 *
 * The localised `step` line uses the per-step key `larafoundry::auth.admin_alert.step.{step}`
 * (password/lockout/admin_otp/pin) so each failure type reads naturally.
 */
class AdminLoginAttemptNotification extends Notification
{
    use Queueable;

    /**
     * @param  string  $step  one of password|lockout|admin_otp|pin
     * @param  DeviceFingerprint|null  $device  coarse device info (optional, backward compatible)
     */
    public function __construct(
        public readonly string $step,
        public readonly string $ip,
        public readonly string $userAgent,
        public readonly ?DeviceFingerprint $device = null,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $message = (new MailMessage)
            ->subject(__('larafoundry::auth.admin_alert.subject'))
            ->line(__('larafoundry::auth.admin_alert.intro', ['step' => $this->stepLabel()]))
            ->line(__('larafoundry::auth.admin_alert.ip', ['ip' => $this->ip]))
            ->line(__('larafoundry::auth.admin_alert.agent', ['agent' => $this->userAgent]));

        if (($device = $this->deviceLabel()) !== null) {
            $message->line(__('larafoundry::auth.admin_alert.device', ['device' => $device]));
        }

        return $message->line(__('larafoundry::auth.admin_alert.outro'));
    }

    /**
     * Human-readable label for the failed step, falling back to the raw key.
     */
    protected function stepLabel(): string
    {
        $key = 'larafoundry::auth.admin_alert.step.'.$this->step;
        $label = __($key);

        return is_string($label) && $label !== $key ? $label : $this->step;
    }

    /**
     * Compact device summary ("Chrome on Windows 10/11"), or null when unknown.
     */
    protected function deviceLabel(): ?string
    {
        if ($this->device === null) {
            return null;
        }

        $parts = array_filter([
            $this->device->browser,
            $this->device->os,
            $this->device->name,
        ]);

        return $parts === [] ? null : implode(' / ', array_unique($parts));
    }
}
