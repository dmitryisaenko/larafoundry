<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Media\Contracts;

use Dmitryisaenko\LaraFoundry\Media\Actions\DeleteStoredFileAction;
use Dmitryisaenko\LaraFoundry\Media\Support\StoredFile;
use Illuminate\Http\UploadedFile;

/**
 * The single seam every file operation in the core goes through (phase 2.4).
 *
 * Call sites depend on this contract, never on `Storage::disk()` directly, for
 * two reasons. First, the disk is configuration (`larafoundry-media.disk`), so
 * the core is portable to S3/local without touching code (the donor wrote into
 * `public_path()` directly — not portable, recon DROP). Second, this is the
 * seam under a future polymorphic media library (variant B, in debt): when a
 * `media` table + `HasMedia` trait land, they implement or wrap this same
 * contract, so the avatar/logo call sites do not change — the same trick as the
 * billing `hasAccess()` stub (phase 1.3) under the billing add-on (phase 3).
 *
 * Implementations MUST generate the stored filename themselves (never trust a
 * client-supplied name) so a caller cannot drive a path traversal.
 */
interface MediaStorage
{
    /**
     * Store an uploaded file (or raw binary string) under the given logical
     * directory and return a descriptor of what was written.
     *
     * @param  UploadedFile|string  $file  an upload, or raw image/file bytes
     * @param  string  $directory  logical sub-path under the disk root, e.g. 'avatars'
     * @param  array<string, mixed>  $options  'disk' (override), 'variants' (image variant keys to also write)
     */
    public function store(UploadedFile|string $file, string $directory, array $options = []): StoredFile;

    /**
     * The publicly resolvable URL for a stored path. For a private disk this is
     * not guaranteed to be reachable — use {@see temporaryUrl()} there instead.
     */
    public function url(string $path, ?string $disk = null): string;

    /**
     * A time-limited signed URL for a stored path (presigned on S3; a signed
     * route on the local/private disk). Use this for private files so a raw
     * path never grants permanent access (recon finding #7). When $minutes is
     * null the configured `temporary_url_minutes` default is used.
     */
    public function temporaryUrl(string $path, ?int $minutes = null, ?string $disk = null): string;

    /**
     * Delete a single stored path. Idempotent: a missing file is a no-op, never
     * an error (recon finding #3, orphan-safe).
     *
     * This does NOT cascade to derived variants — the caller holds the variant
     * paths (StoredFile::$variants) and deletes them too, which is what
     * {@see DeleteStoredFileAction}
     * does. (A future polymorphic media library would track variants per row
     * and cascade there.)
     */
    public function delete(string $path, ?string $disk = null): bool;
}
