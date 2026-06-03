<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\ActivityLog\Http\Resources;

use Dmitryisaenko\LaraFoundry\ActivityLog\Models\Activity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialises one activity entry for the super-admin viewer (phase 2.1).
 *
 * @property Activity $resource
 */
class ActivityLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'log_name' => $this->log_name,
            'description' => $this->description,
            'event' => $this->event,
            'event_group' => $this->properties['event_group'] ?? null,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'causer_id' => $this->causer_id,
            'properties' => $this->properties,
            'user_ip' => $this->user_ip,
            'user_agent' => $this->user_agent,
            'user_device_type' => $this->user_device_type,
            'user_device_name' => $this->user_device_name,
            'user_os' => $this->user_os,
            'user_browser' => $this->user_browser,
            'route_name' => $this->route_name,
            'request_method' => $this->request_method,
            'full_url' => $this->full_url,
            'response_code' => $this->response_code,
            'is_successful' => (bool) $this->is_successful,
            'user_email' => $this->user_email,
            'geo_country' => $this->geo_country,
            'geo_city' => $this->geo_city,
            'created_at' => $this->created_at?->toISOString(),
            'formatted_date' => $this->created_at?->format('d.m.Y H:i:s'),
            'human_date' => $this->created_at
                ?->locale((string) config('app.locale'))
                ->diffForHumans(),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user?->getKey(),
                'name' => $this->user?->name,
                'email' => $this->user?->email,
            ]),
        ];
    }
}
