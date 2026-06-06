<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Media\Actions\StoreUploadedFileAction;
use Dmitryisaenko\LaraFoundry\Media\Contracts\MediaStorage;
use Dmitryisaenko\LaraFoundry\Profile\Http\Requests\UpdateAvatarRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The user's own avatar — upload and remove (phase 5.1).
 *
 * Built entirely on the Media seam (phase 2.4): the upload goes through
 * {@see StoreUploadedFileAction} onto the configured disk (never public_path),
 * with the `avatar` variant doing the resize. Removing reverts to the inline
 * initials placeholder, which needs no stored file — so a user always has a
 * resolvable avatar (`$user->avatar_url`).
 */
class AvatarController extends Controller
{
    public function update(UpdateAvatarRequest $request, StoreUploadedFileAction $storeFile): RedirectResponse
    {
        $user = $request->user();
        $previous = (string) ($user->getAttribute('avatar') ?? '');

        $stored = $storeFile->execute(
            $request->file('avatar'),
            'avatar',
            (string) config('larafoundry-media.paths.avatars', 'avatars'),
            ['variants' => ['avatar']],
        );

        // Keep only the resized 256px variant on the model. store() also writes
        // the full-size original; drop it so it does not orphan on the disk.
        $path = $stored->variant('avatar');
        $user->forceFill(['avatar' => $path])->save();

        if ($stored->path !== $path) {
            app(MediaStorage::class)->delete($stored->path, $stored->disk);
        }

        $this->deleteStored($previous);

        return back()->with('status', __('larafoundry::profile.avatar.updated'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $previous = (string) ($user->getAttribute('avatar') ?? '');

        $user->forceFill(['avatar' => null])->save();

        $this->deleteStored($previous);

        return back()->with('status', __('larafoundry::profile.avatar.removed'));
    }

    /**
     * Delete a previously stored avatar file, skipping external URLs.
     *
     * The avatar column can hold a stored relative path OR an absolute URL set
     * by an OAuth provider at sign-up — only the former is ours to delete. Any
     * value carrying a scheme (`https://`, `data:`) or being protocol-relative
     * (`//host/…`) is external and left alone, so the deletion only ever touches
     * a path we actually wrote on the media disk.
     */
    protected function deleteStored(string $value): void
    {
        $value = trim($value);

        if ($value === ''
            || str_starts_with($value, '//')
            || str_starts_with($value, 'data:')
            || str_contains($value, '://')) {
            return;
        }

        app(MediaStorage::class)->delete($value);
    }
}
