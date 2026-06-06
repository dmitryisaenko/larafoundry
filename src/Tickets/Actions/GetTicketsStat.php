<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tickets\Actions;

use Dmitryisaenko\LaraFoundry\Tickets\Models\Ticket;

/**
 * Aggregates the operator queue counters in one query (phase 4.2).
 *
 * total / open / high-priority-open / standard-priority-open via a single
 * SUM(CASE) pass — O(1) round-trips regardless of ticket count. The
 * 'resolved' status is parameterised (binding) rather than inlined.
 */
class GetTicketsStat
{
    /**
     * @return array{total: int, open: int, high_priority: int, standard_priority: int}
     */
    public function __invoke(): array
    {
        $resolved = Ticket::STATUS_RESOLVED;

        $stats = Ticket::query()
            ->selectRaw('COUNT(*) as total')
            ->selectRaw('SUM(CASE WHEN status != ? THEN 1 ELSE 0 END) as open_count', [$resolved])
            ->selectRaw('SUM(CASE WHEN priority = ? AND status != ? THEN 1 ELSE 0 END) as high_count', ['high', $resolved])
            ->selectRaw('SUM(CASE WHEN priority = ? AND status != ? THEN 1 ELSE 0 END) as standard_count', ['standard', $resolved])
            ->first();

        return [
            'total' => (int) ($stats->total ?? 0),
            'open' => (int) ($stats->open_count ?? 0),
            'high_priority' => (int) ($stats->high_count ?? 0),
            'standard_priority' => (int) ($stats->standard_count ?? 0),
        ];
    }
}
