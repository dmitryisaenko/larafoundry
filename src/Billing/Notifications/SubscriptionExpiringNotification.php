<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Notifications;

use Dmitryisaenko\LaraFoundry\Billing\Console\Commands\CheckExpiringSubscriptions;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Warns a company owner that the subscription is about to lapse (sub-phase B7a).
 *
 * Sent by {@see CheckExpiringSubscriptions}
 * a fixed number of days before `subscription_ends_at`. The donor left email sending as a
 * `// TODO` stub against a host-specific Notification model; this is a real queued mail
 * with no host coupling — the subject/body are `larafoundry::` lang strings the
 * host overrides by publishing the lang files.
 *
 * Queued so the daily cron never blocks on mail delivery. Mail-only on purpose: a
 * database/in-app channel would assume the host ships the Laravel notifications table,
 * which the free core does not yet — a host wanting in-app alerts overrides `via()` or
 * listens for its own signal.
 *
 * Carries SCALARS (company id + name + days), not the company model: the company is an
 * arbitrary host object that may not be Eloquent, so SerializesModels is not safe here,
 * and the mail only needs the name and the day count — serialising the whole model into
 * the queue payload would be bloat and risk a stale snapshot (sub-phase B7b cron-polish).
 */
final class SubscriptionExpiringNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int|string $companyId,
        public readonly string $companyName,
        public readonly int $daysUntilExpiry,
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
        $locale = $this->localeFor($notifiable);

        return (new MailMessage)
            ->subject(__('larafoundry::billing.subscription.expiring.subject', [], $locale))
            ->greeting(__('larafoundry::billing.subscription.expiring.greeting', [], $locale))
            ->line(__('larafoundry::billing.subscription.expiring.line', [
                'days' => $this->daysUntilExpiry,
                'company' => $this->companyName,
            ], $locale))
            ->line(__('larafoundry::billing.subscription.expiring.action', [], $locale));
    }

    /**
     * The recipient's preferred locale, falling back to the app locale.
     */
    private function localeFor(object $notifiable): string
    {
        $locale = $notifiable->locale ?? null;

        return is_string($locale) && $locale !== '' ? $locale : app()->getLocale();
    }
}
