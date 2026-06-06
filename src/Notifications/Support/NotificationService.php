<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Notifications\Support;

use Dmitryisaenko\LaraFoundry\Notifications\Models\Notification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

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
    ): Notification {
        $notification = Notification::create([
            'code' => $code,
            'notification_type' => 'system',
            'status' => 'sent',
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

        if ($ids !== []) {
            $notification->users()->attach($ids);
        }

        return $notification;
    }
}
