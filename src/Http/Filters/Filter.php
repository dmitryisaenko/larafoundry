<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Http\Filters;

use Dmitryisaenko\LaraFoundry\Concerns\Filterable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Base query filter.
 *
 * Subclasses declare one public method per supported query parameter; the
 * method name must match the request key and receives that key's value.
 * Use together with {@see Filterable}.
 *
 * Example:
 *
 *     class ProductFilter extends Filter
 *     {
 *         public function search(string $value): void
 *         {
 *             $this->builder->where('name', 'like', "%{$value}%");
 *         }
 *     }
 *
 *     Product::filter(new ProductFilter($request))->paginate();
 */
abstract class Filter
{
    /**
     * The query builder under construction. Available to filter methods.
     */
    protected Builder $builder;

    public function __construct(protected Request $request) {}

    /**
     * Apply every request parameter that maps to a declared filter method.
     *
     * Only methods declared on the concrete subclass are eligible — methods
     * inherited from this base class (e.g. apply) are never callable from
     * request input, preventing arbitrary method invocation via query keys.
     */
    public function apply(Builder $builder): Builder
    {
        $this->builder = $builder;

        $callable = $this->callableFilters();

        foreach ($this->request->all() as $name => $value) {
            if (! is_string($name) || ! in_array($name, $callable, true)) {
                continue;
            }

            if ($this->shouldSkip($value)) {
                continue;
            }

            $this->{$name}($value);
        }

        return $this->builder;
    }

    /**
     * Filter methods callable from request input.
     *
     * Restricted to *public* methods declared on the concrete subclass:
     * protected/private helpers and anything inherited from this base class
     * stay unreachable, so a request key can never invoke an unintended
     * method (e.g. `?apply=…` or a protected helper).
     *
     * @return array<int, string>
     */
    protected function callableFilters(): array
    {
        $reflection = new \ReflectionClass(static::class);
        $names = [];

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            // Only methods physically declared on the subclass, not inherited.
            if ($method->getDeclaringClass()->getName() === self::class) {
                continue;
            }

            if ($method->isStatic() || $method->isConstructor()) {
                continue;
            }

            $names[] = $method->getName();
        }

        return $names;
    }

    /**
     * Whether a request value should be ignored rather than applied.
     *
     * Empty strings and nulls are skipped so blank inputs do not filter.
     */
    protected function shouldSkip(mixed $value): bool
    {
        return $value === null || $value === '';
    }
}
