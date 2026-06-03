<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Media\Actions;

use Dmitryisaenko\LaraFoundry\Media\Contracts\MediaStorage;
use Dmitryisaenko\LaraFoundry\Media\Events\FileUploaded;
use Dmitryisaenko\LaraFoundry\Media\Support\StoredFile;
use Illuminate\Http\UploadedFile;

/**
 * Store a validated upload through the media storage and announce it (phase
 * 2.4).
 *
 * The one place uploads enter the core: it delegates the write to
 * {@see MediaStorage} (disk-agnostic, generated filename) and fires
 * {@see FileUploaded} so the activity log and hosts can react. Validation is
 * the caller's job (FormRequest); this action assumes the file already passed.
 */
class StoreUploadedFileAction
{
    public function __construct(
        private readonly MediaStorage $storage,
    ) {}

    /**
     * @param  string  $context  what the file is for, e.g. 'avatar', 'company_logo'
     * @param  string  $directory  logical sub-path under the disk, e.g. 'avatars'
     * @param  array<string, mixed>  $options  passed through to MediaStorage::store (disk, variants)
     */
    public function execute(
        UploadedFile|string $file,
        string $context,
        string $directory,
        array $options = [],
    ): StoredFile {
        $stored = $this->storage->store($file, $directory, $options);

        FileUploaded::dispatch($stored, $context);

        return $stored;
    }
}
