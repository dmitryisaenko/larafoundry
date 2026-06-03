<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Media\Support;

use Dmitryisaenko\LaraFoundry\Media\Contracts\MediaStorage;

/**
 * Immutable descriptor of a file the core just wrote (phase 2.4).
 *
 * What {@see MediaStorage::store()}
 * returns. Today a model stores `$stored->path` in its own column (the donor's
 * filename-on-the-model pattern). This DTO is also the seam under the future
 * polymorphic media library (variant B, in debt): a `media` row maps one-to-one
 * onto these fields, so persisting to a table later does not change what callers
 * receive here.
 */
final class StoredFile
{
    /**
     * @param  string  $path  path on the disk, relative to its root (store this on the model)
     * @param  string  $disk  the disk name the file was written to
     * @param  string  $filename  the generated basename (no directory)
     * @param  string|null  $mime  detected mime type, when known
     * @param  int|null  $size  size in bytes, when known
     * @param  array<string, string>  $variants  variant-key => relative path, for derived images
     */
    public function __construct(
        public readonly string $path,
        public readonly string $disk,
        public readonly string $filename,
        public readonly ?string $mime = null,
        public readonly ?int $size = null,
        public readonly array $variants = [],
    ) {}

    /**
     * The relative path of a named image variant, or the original when the
     * variant was not generated.
     */
    public function variant(string $key): string
    {
        return $this->variants[$key] ?? $this->path;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'disk' => $this->disk,
            'filename' => $this->filename,
            'mime' => $this->mime,
            'size' => $this->size,
            'variants' => $this->variants,
        ];
    }
}
