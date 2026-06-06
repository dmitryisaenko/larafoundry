<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tickets\Http\Resources;

use Dmitryisaenko\LaraFoundry\Tickets\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serialises a ticket for the USER-facing inbox / thread (phase 4.2).
 *
 * Labels (operator triage) are deliberately omitted — they are not the
 * customer's concern; the admin resource carries them. Message bodies are
 * delivered as PLAIN TEXT and the frontend renders them as text, never
 * `v-html` (security finding S1). `is_agent` on each message tells the UI which
 * side of the conversation it belongs to.
 *
 * @property Ticket $resource
 */
class TicketResource extends JsonResource
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
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
            'created_human' => $this->created_at?->locale($locale)->diffForHumans(),
            'updated_human' => $this->updated_at?->locale($locale)->diffForHumans(),
            'messages_count' => $this->whenCounted('messages'),
            'messages' => $this->whenLoaded('messages', fn () => $this->messages->map(function ($message) use ($locale) {
                $isAgent = $this->authorIsAgent($message);

                return [
                    'id' => $message->id,
                    'message' => $message->message,
                    'created_at' => $message->created_at?->toISOString(),
                    'created_human' => $message->created_at?->locale($locale)->diffForHumans(),
                    'is_agent' => $isAgent,
                    // The customer never needs the operator's personal name — the
                    // UI shows a generic "Support" label for agent messages, so do
                    // not leak it in the payload either. Only the customer's own
                    // author name is exposed.
                    'author' => [
                        'id' => $isAgent ? null : $message->user?->getKey(),
                        'name' => $isAgent ? null : $message->user?->name,
                    ],
                ];
            })),
        ];
    }

    /**
     * Whether a message was written by the support side (an operator), so the
     * thread can render the two sides distinctly.
     */
    protected function authorIsAgent(object $message): bool
    {
        return $message->user !== null
            && method_exists($message->user, 'isAdmin')
            && $message->user->isAdmin();
    }
}
