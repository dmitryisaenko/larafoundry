<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Profile\Contracts\PurgesUserData;
use Dmitryisaenko\LaraFoundry\Profile\Support\UserDataPurgeRegistry;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A bare purger recording the order it ran in, used to test ordering/guards
 * without a database.
 */
function purgeProvider(string $key, int $priority, array &$ran): PurgesUserData
{
    return new class($key, $priority, $ran) implements PurgesUserData
    {
        /** @param array<int, string> $ran */
        public function __construct(
            private string $key,
            private int $priority,
            private array &$ran,
        ) {}

        public function purgeFor(Authenticatable $user): void
        {
            $this->ran[] = $this->key;
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

it('runs each purger in priority order, lowest first', function () {
    $ran = [];
    $registry = (new UserDataPurgeRegistry)
        ->addProvider(purgeProvider('tickets', 40, $ran))
        ->addProvider(purgeProvider('profile', 0, $ran));

    $registry->purge(Mockery::mock(Authenticatable::class));

    expect($ran)->toBe(['profile', 'tickets']);
});

it('rejects a second purger for an already-registered section key', function () {
    $ran = [];
    $registry = (new UserDataPurgeRegistry)
        ->addProvider(purgeProvider('profile', 0, $ran));

    expect(fn () => $registry->addProvider(purgeProvider('profile', 10, $ran)))
        ->toThrow(InvalidArgumentException::class);
});
