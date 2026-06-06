<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Notifications\Concerns;

use Dmitryisaenko\LaraFoundry\Notifications\Models\Notification;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Gives a host user model its in-app notification inbox (phase 4.1).
 *
 * Deliberately named `appNotifications()` rather than `notifications()` so it
 * never collides with Laravel's `Notifiable::notifications()` (the database
 * channel) — a host can keep `Notifiable` for mail and add this trait for the
 * in-app centre side by side. One `use` per the trait-slot idiom.
 */
trait HasNotifications
{
    /**
     * The user's in-app notifications, newest first, with `read_at` on the pivot.
     *
     * @return BelongsToMany<Notification, $this>
     */
    public function appNotifications(): BelongsToMany
    {
        return $this->belongsToMany(
            Notification::class,
            'larafoundry_notification_user',
            'user_id',
            'notification_id',
        )
            ->withPivot('read_at')
            ->withTimestamps()
            ->orderByDesc('larafoundry_notifications.created_at');
    }

    /**
     * How many of the user's notifications are still unread.
     */
    public function unreadAppNotificationsCount(): int
    {
        return $this->appNotifications()->wherePivot('read_at', null)->count();
    }
}
