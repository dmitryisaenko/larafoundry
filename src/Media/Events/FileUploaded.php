<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Media\Events;

use Dmitryisaenko\LaraFoundry\Media\Actions\StoreUploadedFileAction;
use Dmitryisaenko\LaraFoundry\Media\Support\StoredFile;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A file was successfully stored through the core (phase 2.4).
 *
 * Dispatched by {@see StoreUploadedFileAction}.
 * Hosts and the activity log (phase 2.1) can listen to audit uploads; the
 * `context` string lets a listener tell an avatar from a logo without inspecting
 * the path.
 */
class FileUploaded
{
    use Dispatchable;

    /**
     * @param  string  $context  what the file is for, e.g. 'avatar', 'company_logo'
     */
    public function __construct(
        public readonly StoredFile $file,
        public readonly string $context,
    ) {}

    /**
     * Properties surfaced into an activity-log entry when this event is in the
     * log registry (phase 2.1 reads `getLogProperties()` if present).
     *
     * @return array<string, mixed>
     */
    public function getLogProperties(): array
    {
        return [
            'context' => $this->context,
            'disk' => $this->file->disk,
            'mime' => $this->file->mime,
            'size' => $this->file->size,
        ];
    }
}
