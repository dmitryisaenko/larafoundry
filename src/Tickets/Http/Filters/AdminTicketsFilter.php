<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tickets\Http\Filters;

use Dmitryisaenko\LaraFoundry\Http\Filters\Filter;
use Dmitryisaenko\LaraFoundry\Tickets\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;

/**
 * Query filter for the operator ticket queue (phase 4.2).
 *
 * Extends the reflection-based {@see Filter}: only the public methods here are
 * callable from request keys. Rewritten from the donor `AdminTicketsFilter`,
 * which had an inverted `show_tickets` branch (its `high_priority` case did
 * `where priority != 'high'`, EXCLUDING high) and read the global `request()`
 * inside the sort. Here `show_tickets`/the default open-queue restriction live
 * in the overridden apply() (driven by the injected request), and the workflow
 * ordering is shared with the model scope.
 */
class AdminTicketsFilter extends Filter
{
    /**
     * Free-text search across title and message.
     */
    public function search(string $value): void
    {
        $term = '%'.$value.'%';

        $this->builder->where(function (Builder $query) use ($term) {
            $query->where('title', 'like', $term)
                ->orWhere('message', 'like', $term);
        });
    }

    /**
     * Filter by an explicit status (one of the configured slugs).
     *
     * A value outside the configured set is ignored rather than applied, so a
     * typo'd / stale query parameter shows the normal queue instead of silently
     * returning nothing.
     */
    public function status(string $value): void
    {
        if (in_array($value, (array) config('larafoundry-tickets.statuses', []), true)) {
            $this->builder->where('status', $value);
        }
    }

    /**
     * Filter by priority (one of the configured slugs). Unknown values ignored.
     */
    public function priority(string $value): void
    {
        if (in_array($value, (array) config('larafoundry-tickets.priorities', []), true)) {
            $this->builder->where('priority', $value);
        }
    }

    /**
     * Apply the declared filters, then default to the OPEN queue and always sort
     * by the support workflow.
     *
     * Resolved tickets are hidden unless the operator explicitly asks for them
     * (`show_tickets=all`) or filters by an explicit status. `show_tickets` is
     * handled here rather than as a filter method so it can never conflict with
     * an explicit `status` filter.
     *
     * @param  Builder<Ticket>  $builder
     * @return Builder<Ticket>
     */
    public function apply(Builder $builder): Builder
    {
        $builder = parent::apply($builder);

        $showAll = $this->request->input('show_tickets') === 'all';
        $hasValidStatus = in_array(
            $this->request->input('status'),
            (array) config('larafoundry-tickets.statuses', []),
            true,
        );

        // Default to the open queue unless the operator asked for everything or
        // is filtering by an explicit (valid) status — a status() that ignored an
        // unknown value must not flip the queue to "show all".
        if (! $showAll && ! $hasValidStatus) {
            $builder->where('status', '!=', Ticket::STATUS_RESOLVED);
        }

        return $builder->workflowOrder();
    }
}
