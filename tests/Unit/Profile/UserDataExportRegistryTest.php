<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Profile\Contracts\ExportsUserDataProvider;
use Dmitryisaenko\LaraFoundry\Profile\Support\UserDataExportRegistry;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A bare provider returning fixed data, used to test merge/ordering without DB.
 */
function exportProvider(string $key, int $priority, array $data): ExportsUserDataProvider
{
    return new class($key, $priority, $data) implements ExportsUserDataProvider
    {
        public function __construct(
            private string $key,
            private int $priority,
            private array $data,
        ) {}

        public function exportFor(Authenticatable $user): array
        {
            return $this->data;
        }

        public function key(): string
        {
            return $this->key;
        }

        public function priority(): int
        {
            return $this->priority;
        }
    };
}

it('collects each provider under its section key', function () {
    $registry = (new UserDataExportRegistry)
        ->addProvider(exportProvider('profile', 0, ['name' => 'Ada']))
        ->addProvider(exportProvider('tickets', 10, ['count' => 3]));

    $export = $registry->collect(Mockery::mock(Authenticatable::class));

    expect($export)->toBe([
        'profile' => ['name' => 'Ada'],
        'tickets' => ['count' => 3],
    ]);
});

it('orders providers by priority, lowest first', function () {
    $registry = (new UserDataExportRegistry)
        ->addProvider(exportProvider('b', 10, []))
        ->addProvider(exportProvider('a', 0, []));

    expect(array_keys($registry->collect(Mockery::mock(Authenticatable::class))))
        ->toBe(['a', 'b']);
});
