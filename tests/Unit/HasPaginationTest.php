<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Support\Pagination\HasPagination;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

/**
 * Exposes the protected trait method through a tiny host object.
 */
function paginationHost(): object
{
    return new class
    {
        use HasPagination;

        public function expose(mixed $paginator): array
        {
            return $this->getPaginationData($paginator);
        }
    };
}

it('normalises a length-aware paginator into a flat payload', function () {
    $items = collect(range(1, 25));
    $paginator = new LengthAwarePaginator($items->forPage(2, 10), 25, 10, 2);

    $data = paginationHost()->expose($paginator);

    expect($data)->toMatchArray([
        'current_page' => 2,
        'last_page' => 3,
        'per_page' => 10,
        'total' => 25,
    ]);
    expect($data['from'])->toBe(11);
    expect($data['to'])->toBe(20);
});

it('handles a simple paginator without total/last_page', function () {
    $paginator = new Paginator(collect(range(1, 10)), 10, 1);

    $data = paginationHost()->expose($paginator);

    expect($data['current_page'])->toBe(1);
    expect($data['per_page'])->toBe(10);
    expect($data)->toHaveKeys(['from', 'to', 'last_page', 'total']);
});

it('returns an empty array for non-paginator input', function () {
    $host = paginationHost();

    expect($host->expose(null))->toBe([]);
    expect($host->expose(['just', 'an', 'array']))->toBe([]);
    expect($host->expose('string'))->toBe([]);
});
