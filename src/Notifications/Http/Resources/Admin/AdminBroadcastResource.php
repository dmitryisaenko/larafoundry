<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Notifications\Http\Resources\Admin;

use Dmitryisaenko\LaraFoundry\Notifications\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialises one broadcast for the super-admin console list (phase 4.1).
 *
 * Carries the operator-facing shape: status, the English title for the table,
 * the audience snapshot and the delivery counters (`total_recipients` /
 * `read_count`) the controller eager-counts.
 *
 * @property Notification $resource
 */
class AdminBroadcastResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $titles = $this->title_translations ?? [];

        return [
            'id' => $this->id,
            'code' => $this->code,
            'status' => $this->status,
            'title' => $titles['en'] ?? reset($titles) ?: '',
            'recipient_filters' => $this->recipient_filters,
            'total_recipients' => $this->whenCounted('users'),
            'read_count' => $this->when(isset($this->read_count), fn () => (int) $this->read_count),
            'visible_from' => $this->visible_from?->toISOString(),
            'visible_until' => $this->visible_until?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
