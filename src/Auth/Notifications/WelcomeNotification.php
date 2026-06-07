<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Auth\Notifications;

use Dmitryisaenko\LaraFoundry\Auth\Listeners\SendWelcomeNotification;
use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Welcomes a user once their email is verified (phase 5.1, decision D-5.1-13).
 *
 * Sent by {@see SendWelcomeNotification}
 * on the Verified event, so it follows the verification mail rather than racing
 * or duplicating it. Body comes from the editable `welcome_email` template (a
 * super-admin can rewrite it), falling back to the core's `larafoundry::auth`
 * welcome strings when that template is switched off (graceful, D-5.1-8).
 */
class WelcomeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $appName = (string) config('app.name', 'LaraFoundry');
        $loginUrl = url('/login');

        $templated = app(EmailTemplateRepository::class)->mailMessage(
            'welcome_email',
            $notifiable->locale ?? null,
            [
                'name' => (string) ($notifiable->name ?? ''),
                'app_name' => $appName,
                'login_url' => $loginUrl,
                'support_url' => url('/support'),
            ],
        );

        if ($templated !== null) {
            return $templated;
        }

        return (new MailMessage)
            ->subject(__('larafoundry::auth.welcome.subject', ['app' => $appName]))
            ->line(__('larafoundry::auth.welcome.intro', ['app' => $appName]))
            ->action(__('larafoundry::auth.welcome.action'), $loginUrl)
            ->line(__('larafoundry::auth.welcome.outro'));
    }
}
