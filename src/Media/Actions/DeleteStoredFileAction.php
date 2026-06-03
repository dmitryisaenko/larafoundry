<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Media\Actions;

use Dmitryisaenko\LaraFoundry\Media\Contracts\MediaStorage;

/**
 * Delete a stored file and any of its derived variants (phase 2.4).
 *
 * Idempotent through {@see MediaStorage::delete()}: deleting a missing path is a
 * no-op. Used when a model's image is replaced or the model is removed, so old
 * files do not orphan (recon finding #3).
 */
class DeleteStoredFileAction
{
    public function __construct(
        private readonly MediaStorage $storage,
    ) {}

    /**
     * @param  string  $path  the stored path to delete
     * @param  array<int, string>  $variantPaths  derived variant paths to delete alongside it
     */
    public function execute(string $path, array $variantPaths = [], ?string $disk = null): void
    {
        $this->storage->delete($path, $disk);

        foreach ($variantPaths as $variantPath) {
            $this->storage->delete($variantPath, $disk);
        }
    }
}
