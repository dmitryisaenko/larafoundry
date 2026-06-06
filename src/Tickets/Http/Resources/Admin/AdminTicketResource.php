<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tickets\Http\Resources\Admin;

use Dmitryisaenko\LaraFoundry\Tickets\Http\Resources\TicketResource;
use Dmitryisaenko\LaraFoundry\Tickets\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialises a ticket for the OPERATOR console (phase 4.2).
 *
 * The admin view of {@see TicketResource}:
 * adds the triage labels and the `is_new` flag, and exposes the author's email
 * (the operator needs to know who they are helping). Message bodies are still
 * delivered as PLAIN TEXT — the console renders them as text, never `v-html`.
 *
 * @property Ticket $resource
 */
class AdminTicketResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            'uuid' => $this->uuid,
            'title' => $this->title,
            'message' => $this->message,
            'status' => $this->status,
            'priority' => $this->priority,
            'categories' => $this->categories ?? [],
            'labels' => $this->labels ?? [],
            'is_new' => $this->isNew(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'created_human' => $this->created_at?->locale($locale)->diffForHumans(),
            'updated_human' => $this->updated_at?->locale($locale)->diffForHumans(),
            'messages_count' => $this->whenCounted('messages'),
            'user' => $this->whenLoaded('user', fn () => [
                'id' => $this->user?->getKey(),
                'name' => $this->user?->name,
                'email' => $this->user?->email,
            ]),
            'messages' => $this->whenLoaded('messages', fn () => $this->messages->map(fn ($message) => [
                'id' => $message->id,
                'message' => $message->message,
                'created_at' => $message->created_at?->toISOString(),
                'created_human' => $message->created_at?->locale($locale)->diffForHumans(),
                'is_agent' => $this->authorIsAgent($message),
                'author' => [
                    'id' => $message->user?->getKey(),
                    'name' => $message->user?->name,
                ],
            ])),
        ];
    }

    protected function authorIsAgent(object $message): bool
    {
        return $message->user !== null
            && method_exists($message->user, 'isAdmin')
            && $message->user->isAdmin();
    }
}
