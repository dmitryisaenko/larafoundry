<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Media\Support;

use Dmitryisaenko\LaraFoundry\Media\Contracts\MediaStorage;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * The core's default {@see MediaStorage} — everything written goes through
 * Laravel's filesystem on a CONFIGURED disk, never `public_path()` (phase 2.4).
 *
 * Filenames are generated (`<uuid>.<ext>`) and never taken from client input,
 * which is what closes path traversal (recon finding #6): a caller cannot steer
 * the destination by sending `../../etc/...` as a name. Files shard into
 * `<directory>/YYYY/MM/` to keep any one directory from growing unbounded
 * (donor pattern).
 */
class FileStorageManager implements MediaStorage
{
    public function __construct(
        private readonly ImageProcessor $images,
    ) {}

    public function store(UploadedFile|string $file, string $directory, array $options = []): StoredFile
    {
        $disk = $options['disk'] ?? $this->defaultDisk();
        $variants = $options['variants'] ?? [];

        $extension = $this->resolveExtension($file, $variants);
        $stem = Str::uuid()->toString();
        $filename = $stem.'.'.$extension;
        $relativeDir = $this->shardedDirectory($directory);
        $path = $relativeDir.'/'.$filename;

        $fs = $this->fs($disk);

        // Image inputs are re-encoded through intervention (which also acts as a
        // de-facto content check — a non-image throws here rather than being
        // stored as one, recon finding #2). Non-image uploads are stored as-is.
        if ($this->shouldProcessAsImage($file, $variants)) {
            $source = $file instanceof UploadedFile ? $file->getRealPath() : $file;

            // Decode the source once; the original and every variant encode from
            // the same decoded image instead of re-reading the bytes per output.
            $image = $this->images->decode($source);

            $fs->put($path, $this->images->encode($image));

            // Variants share the original's stem (`<stem>_<key>.jpg`), so they
            // sit beside it and can be recognised as derivatives of one file.
            $variantPaths = [];
            foreach ($variants as $variantKey) {
                $variantPath = $relativeDir.'/'.$stem.'_'.$variantKey.'.'.$extension;
                $fs->put($variantPath, $this->images->encode($image, $variantKey));
                $variantPaths[$variantKey] = $variantPath;
            }

            return new StoredFile(
                path: $path,
                disk: $disk,
                filename: $filename,
                mime: 'image/jpeg',
                size: $this->sizeOrNull($fs, $path),
                variants: $variantPaths,
            );
        }

        if ($file instanceof UploadedFile) {
            $file->storeAs($relativeDir, $filename, ['disk' => $disk]);
        } else {
            $fs->put($path, $file);
        }

        return new StoredFile(
            path: $path,
            disk: $disk,
            filename: $filename,
            mime: $file instanceof UploadedFile ? $file->getMimeType() : null,
            size: $this->sizeOrNull($fs, $path),
        );
    }

    /**
     * The stored file's size, or null if it was not actually written — so a
     * silent put() failure (some adapters return false rather than throw)
     * yields a null size instead of a metadata lookup on a missing path.
     */
    private function sizeOrNull(Filesystem $fs, string $path): ?int
    {
        return $fs->exists($path) ? $fs->size($path) : null;
    }

    public function url(string $path, ?string $disk = null): string
    {
        return $this->fs($disk ?? $this->defaultDisk())->url($path);
    }

    public function temporaryUrl(string $path, ?int $minutes = null, ?string $disk = null): string
    {
        $disk ??= (string) config('larafoundry-media.private_disk', 'local');
        $minutes ??= (int) config('larafoundry-media.temporary_url_minutes', 5);

        // The strategy is explicit config, not adapter auto-detection: a faked
        // local disk also advertises temporary URLs, so branching on
        // providesTemporaryUrls() would behave differently under test than in
        // production. 'presigned' delegates to the disk (S3); the default
        // 'signed-route' issues a short-lived signed URL to the core's
        // auth-gated private-file route — unforgeable and expiring on any disk
        // (recon finding #7).
        $strategy = (string) config('larafoundry-media.private_url_strategy', 'signed-route');

        if ($strategy === 'presigned') {
            return $this->fs($disk)->temporaryUrl($path, now()->addMinutes($minutes));
        }

        // Sign BOTH the path and the disk, so a file on a non-default private
        // disk is served from the right disk and neither value can be tampered
        // after signing (the controller re-validates the disk against config).
        return URL::temporarySignedRoute(
            'larafoundry.media.private',
            now()->addMinutes($minutes),
            ['path' => $path, 'disk' => $disk],
        );
    }

    public function delete(string $path, ?string $disk = null): bool
    {
        $fs = $this->fs($disk ?? $this->defaultDisk());

        // Idempotent: Storage::delete returns true for a missing file too, so a
        // double-delete or a never-stored path is a no-op rather than an error
        // (recon finding #3, orphan-safe).
        return $fs->delete($path);
    }

    private function defaultDisk(): string
    {
        return (string) config('larafoundry-media.disk', 'public');
    }

    private function fs(string $disk): Filesystem
    {
        return Storage::disk($disk);
    }

    /**
     * `<directory>/YYYY/MM` — date shards under the logical directory.
     */
    private function shardedDirectory(string $directory): string
    {
        return trim($directory, '/').'/'.now()->format('Y').'/'.now()->format('m');
    }

    private function shouldProcessAsImage(UploadedFile|string $file, array $variants): bool
    {
        if ($variants !== []) {
            return true;
        }

        if (! $file instanceof UploadedFile) {
            return false;
        }

        $mime = (string) $file->getMimeType();

        return str_starts_with($mime, 'image/');
    }

    /**
     * Images are always normalised to jpg; other files keep their real
     * extension (never the client-sent one — derived from the upload's guessed
     * extension, falling back to `bin`).
     */
    private function resolveExtension(UploadedFile|string $file, array $variants): string
    {
        if ($this->shouldProcessAsImage($file, $variants)) {
            return 'jpg';
        }

        if ($file instanceof UploadedFile) {
            $guessed = $file->guessExtension();

            return $guessed !== null && $guessed !== '' ? $guessed : 'bin';
        }

        return 'bin';
    }
}
