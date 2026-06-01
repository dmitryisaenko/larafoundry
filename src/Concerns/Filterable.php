<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Concerns;

use Dmitryisaenko\LaraFoundry\Http\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;

/**
 * Adds a `filter` query scope that runs a {@see Filter} over the builder.
 *
 * Usage on a model:
 *
 *     use Filterable;
 *
 *     Product::filter(new ProductFilter($request))->paginate();
 */
trait Filterable
{
    /**
     * Apply the given filter to the query.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFilter(Builder $query, Filter $filter): Builder
    {
        return $filter->apply($query);
    }
}
