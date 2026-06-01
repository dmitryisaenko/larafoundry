<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Support\Pagination;

use Illuminate\Contracts\Pagination\Paginator as PaginatorContract;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * Normalises a paginator into a flat array suitable for Inertia props.
 *
 * Pair with the frontend `PagePaginator` component, which consumes the
 * `pagination` prop shaped by {@see HasPagination::getPaginationData()}.
 */
trait HasPagination
{
    /**
     * Build a serialisable pagination payload from a paginator instance.
     *
     * Returns an empty array when given anything that is not a paginator,
     * so callers can spread the result unconditionally.
     *
     * @param  mixed  $paginator
     * @return array<string, int|null>
     */
    protected function getPaginationData($paginator): array
    {
        if (! $paginator instanceof PaginatorContract
            && ! $paginator instanceof Paginator
            && ! $paginator instanceof LengthAwarePaginator) {
            return [];
        }

        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => method_exists($paginator, 'lastPage') ? $paginator->lastPage() : null,
            'per_page' => $paginator->perPage(),
            'total' => method_exists($paginator, 'total') ? $paginator->total() : null,
            'from' => $paginator->firstItem(),
            'to' => $paginator->lastItem(),
        ];
    }
}
