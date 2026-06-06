<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Notifications\Http\Resources;

use Dmitryisaenko\LaraFoundry\Notifications\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialises one notification for the recipient's inbox / bell (phase 4.1).
 *
 * Title and body are pre-localised for the active locale and delivered as PLAIN
 * TEXT — the frontend renders them as text, never `v-html` (security finding
 * #1). `data.actions` is reduced to internal GET links only (finding #5) so a
 * notification can never drive an outbound or state-changing request.
 *
 * @property Notification $resource
 */
class UserNotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            // Visual type: system notifications render in the 'system' style;
            // admin broadcasts use their declared code (info/warning/…).
            'type' => $this->notification_type === 'system' ? 'system' : $this->code,
            'title' => $this->localizedTitle($locale),
            'body' => $this->localizedBody($locale),
            'actions' => $this->safeActions(),
            'created_at' => $this->created_at?->toISOString(),
            'created_human' => $this->created_at?->locale($locale)->diffForHumans(),
            'read_at' => $this->pivot?->read_at,
        ];
    }

    /**
     * The notification's actions, kept only if they point at an internal,
     * same-origin path. Method is intentionally dropped: the frontend renders
     * each as a GET link, so a notification can never trigger a POST/DELETE or
     * an off-site redirect from its stored `data` (finding #5).
     *
     * @return array<int, array{label: string, url: string}>
     */
    protected function safeActions(): array
    {
        $actions = $this->data['actions'] ?? null;

        if (! is_array($actions)) {
            return [];
        }

        $safe = [];

        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            $url = $action['url'] ?? null;

            // Same-origin relative path only: a single leading slash, never a
            // protocol-relative '//host' — and never a backslash anywhere, since
            // a browser normalises '/\host' (or '\\') to '//host' and would leave
            // the origin. A legitimate internal path holds no backslash.
            if (! is_string($url)
                || ! str_starts_with($url, '/')
                || str_starts_with($url, '//')
                || str_contains($url, '\\')) {
                continue;
            }

            $safe[] = [
                'label' => (string) ($action['label'] ?? ''),
                'url' => $url,
            ];
        }

        return $safe;
    }
}
