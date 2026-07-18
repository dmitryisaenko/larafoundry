<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\MagicLink\Notifications;

use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mails the one-time passwordless sign-in link (phase: magic-link).
 *
 * Sent on demand to a raw address (the recipient may own no account yet — the
 * first click registers it), so it carries no `name`: the greeting is neutral.
 * Body renders from the editable `magic_login_link` template (a super-admin can
 * rewrite it), falling back to a MailMessage built from the core's
 * `larafoundry::auth.magic_link` strings when that template is switched off
 * (graceful, decision D-5.1-8).
 *
 * NOT queued: a login link must arrive promptly, and the core's shared-hosting
 * target runs the queue on database+cron (a queued mail could sit until the next
 * tick). The request path does the same work whether or not the email exists, so
 * sending inline keeps the anti-enumeration timing even.
 */
class MagicLoginLinkNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly string $url,
        // Not named `locale`: the base Notification already declares a $locale
        // property (its own translator-locale seam), and redeclaring it readonly
        // fatals. This is the locale we render the email TEMPLATE in.
        public readonly ?string $mailLocale = null,
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
        $minutes = (int) config('larafoundry.auth.magic_link.ttl_minutes', 15);
        $appName = (string) config('app.name', 'LaraFoundry');

        $templated = app(EmailTemplateRepository::class)->mailMessage(
            'magic_login_link',
            $this->mailLocale,
            [
                'app_name' => $appName,
                'login_url' => $this->url,
                'minutes' => (string) $minutes,
            ],
        );

        if ($templated !== null) {
            return $templated;
        }

        return (new MailMessage)
            ->subject(__('larafoundry::auth.magic_link.subject', ['app' => $appName]))
            ->line(__('larafoundry::auth.magic_link.intro', ['minutes' => $minutes]))
            ->action(__('larafoundry::auth.magic_link.action'), $this->url)
            ->line(__('larafoundry::auth.magic_link.outro'));
    }
}
