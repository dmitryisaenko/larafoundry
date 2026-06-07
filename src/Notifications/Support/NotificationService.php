<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Notifications\Support;

use Dmitryisaenko\LaraFoundry\Notifications\Jobs\SendBroadcastNotificationJob;
use Dmitryisaenko\LaraFoundry\Notifications\Models\Notification;
use Dmitryisaenko\LaraFoundry\Notifications\Notifications\InAppMailNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * The core's seam for pushing a SYSTEM in-app notification (phase 4.1).
 *
 * The host's domain (orders, tickets, …) calls this instead of the donor's
 * per-event jobs: it creates one notification row and attaches the given users.
 * Wording is translation-keys (`titleKey`/`bodyKey` + `params`) so the message
 * localises per recipient at read time and the host overrides text via lang
 * files — the same pattern as the core's mail notifications.
 */
class NotificationService
{
    /**
     * Push a system notification to one or more users.
     *
     * Set `$mail` to also email each recipient (opt-in, decision D-5.1-14): the
     * mail is queued per recipient and is gated by the global `channels` master
     * switch, so it sends only when both this flag and config opt in. Default
     * false — domain pushes stay in-app unless the host asks for email.
     *
     * @param  iterable<Model|int|string>  $users  user models or ids
     * @param  array<string, mixed>  $params  translation placeholders
     * @param  array<string, mixed>  $data  UI payload (internal-link actions, context)
     */
    public function system(
        iterable $users,
        string $code,
        string $titleKey,
        ?string $bodyKey = null,
        array $params = [],
        array $data = [],
        bool $mail = false,
    ): Notification {
        $notification = Notification::create([
            'code' => $code,
            'notification_type' => 'system',
            'status' => 'sent',
            'send_mail' => $mail,
            'title_key' => $titleKey,
            'body_key' => $bodyKey,
            'params' => $params,
            'data' => $data,
        ]);

        $ids = Collection::make($users)
            ->map(fn ($user) => $user instanceof Model ? $user->getKey() : $user)
            ->filter(fn ($id) => $id !== null)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return $notification;
        }

        $notification->users()->attach($ids);

        if ($mail && static::mailChannelEnabled()) {
            $this->emailRecipients($ids, $notification->id);
        }

        return $notification;
    }

    /**
     * Whether the notification centre's `mail` channel is enabled (the global
     * master switch). The per-notification opt-in only emails when this is on.
     */
    public static function mailChannelEnabled(): bool
    {
        return in_array('mail', (array) config('larafoundry-notifications.channels', []), true);
    }

    /**
     * Queue the in-app notification's email copy to a resolved set of recipients.
     *
     * The single seam both the system push (here) and the chunked broadcast
     * fan-out ({@see SendBroadcastNotificationJob})
     * call, so the per-recipient mail is built identically from either path.
     *
     * @param  Collection<int, Model>  $recipients
     */
    public static function dispatchInAppMail(Collection $recipients, int $notificationId): void
    {
        if ($recipients->isNotEmpty()) {
            NotificationFacade::send($recipients, new InAppMailNotification($notificationId));
        }
    }

    /**
     * Queue the in-app notification's email copy to the given user ids.
     *
     * @param  list<int|string>  $ids
     */
    protected function emailRecipients(array $ids, int $notificationId): void
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        static::dispatchInAppMail($model::query()->findMany($ids), $notificationId);
    }
}
