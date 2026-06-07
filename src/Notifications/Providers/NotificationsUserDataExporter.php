<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Notifications\Providers;

use Dmitryisaenko\LaraFoundry\Notifications\Models\Notification;
use Dmitryisaenko\LaraFoundry\Profile\Contracts\ExportsUserDataProvider;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Exports the user's in-app notifications (phase 5.3).
 *
 * The notifications module owns this section, added to the export registry from
 * bootNotifications(). Each row is rendered to the text the user actually saw —
 * resolved in their own locale — with the per-recipient receipt/read timestamps
 * from the pivot. Only the user's OWN inbox is read (the appNotifications
 * relation is pivot-scoped to them).
 */
class NotificationsUserDataExporter implements ExportsUserDataProvider
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function exportFor(Authenticatable $user): array
    {
        if (! method_exists($user, 'appNotifications')) {
            return [];
        }

        $locale = $user instanceof Model && is_string($user->getAttribute('locale'))
            ? $user->getAttribute('locale')
            : app()->getLocale();

        // No ->visible() window filter on purpose: a portability export is every
        // notification held FOR this user, including ones scheduled or expired out
        // of the live inbox — the subject is entitled to all data held about them.
        return $user->appNotifications()
            ->get()
            ->map(function (Notification $notification) use ($locale) {
                $readAt = $notification->pivot->read_at ?? null;

                return [
                    'title' => $notification->localizedTitle($locale),
                    'body' => $notification->localizedBody($locale),
                    'type' => $notification->notification_type,
                    'received_at' => optional($notification->pivot->created_at)->toIso8601String(),
                    'read_at' => $readAt !== null ? Carbon::parse($readAt)->toIso8601String() : null,
                ];
            })
            ->values()
            ->all();
    }

    public function key(): string
    {
        return 'notifications';
    }

    public function priority(): int
    {
        return 50;
    }
}
