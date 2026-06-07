<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Notifications\Notifications;

use Dmitryisaenko\LaraFoundry\Notifications\Models\Notification as NotificationModel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Emails one recipient their copy of an in-app notification (phase 5.1, D-5.1-14).
 *
 * The mail arm of the notification centre's `mail` channel: a system push or an
 * admin broadcast that opted into email queues one of these per recipient (the
 * opt-in is per notification, gated by the global `channels` master switch). The
 * subject/body are the source row's title/body resolved for THIS recipient's
 * locale, so a broadcast emails each user in their own language. These are short
 * notices, so it uses plain MailMessage lines, not the HTML transactional
 * templates of the email-template editor.
 *
 * Only the source row id is carried (not the model), so a queued copy never
 * serialises a stale snapshot and re-reads the live row at send time.
 */
class InAppMailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $notificationId,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        // Skip silently if the source row was deleted between queueing and send.
        return $this->source() !== null ? ['mail'] : [];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $source = $this->source();
        $message = new MailMessage;

        if ($source === null) {
            return $message; // unreachable in practice — via() already filtered.
        }

        $locale = $notifiable->locale ?? app()->getLocale();

        $message->subject($source->localizedTitle($locale));

        $body = $source->localizedBody($locale);

        if ($body !== '') {
            $message->line($body);
        }

        return $message;
    }

    protected function source(): ?NotificationModel
    {
        return NotificationModel::find($this->notificationId);
    }
}
