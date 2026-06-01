<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Http\Filters\Filter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Concrete filter used across the cases below. Records which filter methods
 * ran and with what value so we can assert dispatch behaviour without a DB.
 */
function makeFilter(Request $request): Filter
{
    return new class($request) extends Filter
    {
        /** @var array<string, mixed> */
        public array $applied = [];

        public bool $protectedRan = false;

        public function search(mixed $value): void
        {
            $this->applied['search'] = $value;
        }

        public function status(mixed $value): void
        {
            $this->applied['status'] = $value;
        }

        // Must never be reachable from a request key, even though it exists.
        protected function secret(mixed $value): void
        {
            $this->protectedRan = true;
        }
    };
}

function fakeBuilder(): Builder
{
    return Mockery::mock(Builder::class);
}

it('dispatches request keys to matching filter methods', function () {
    $filter = makeFilter(new Request(['search' => 'acme', 'status' => 'active']));

    $filter->apply(fakeBuilder());

    expect($filter->applied)->toBe(['search' => 'acme', 'status' => 'active']);
});

it('ignores request keys with no matching method', function () {
    $filter = makeFilter(new Request(['unknown' => 'x', 'search' => 'ok']));

    $filter->apply(fakeBuilder());

    expect($filter->applied)->toBe(['search' => 'ok']);
});

it('never invokes methods inherited from the base Filter via a request key', function () {
    // `apply` is a real public method on the base class — a naive
    // method_exists() check would have called it recursively.
    $filter = makeFilter(new Request(['apply' => 'boom', 'callableFilters' => 'x']));

    $result = $filter->apply(fakeBuilder());

    expect($filter->applied)->toBe([]);
    expect($result)->toBeInstanceOf(Builder::class);
});

it('does not invoke protected helper methods declared on the subclass', function () {
    $filter = makeFilter(new Request(['secret' => 'leak']));

    $filter->apply(fakeBuilder());

    expect($filter->protectedRan)->toBeFalse();
});

it('skips empty and null values', function () {
    $filter = makeFilter(new Request(['search' => '', 'status' => null]));

    $filter->apply(fakeBuilder());

    expect($filter->applied)->toBe([]);
});

it('returns the builder it was given', function () {
    $builder = fakeBuilder();
    $filter = makeFilter(new Request([]));

    expect($filter->apply($builder))->toBe($builder);
});
