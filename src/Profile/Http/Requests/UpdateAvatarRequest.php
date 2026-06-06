<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an avatar upload (phase 5.1).
 *
 * Limits come from the media config so they match the rest of the file layer:
 * the accepted image set (`image_mimes`) and the hard size ceiling
 * (`max_upload_kb`). `image` plus the mime allowlist keeps a renamed
 * non-image out; the FileStorageManager re-encodes on store as a second check.
 */
class UpdateAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $mimes = config('larafoundry-media.image_mimes', ['jpg', 'jpeg', 'png', 'webp']);
        $maxKb = (int) config('larafoundry-media.max_upload_kb', 5120);

        return [
            'avatar' => [
                'required',
                'image',
                'mimes:'.implode(',', is_array($mimes) ? $mimes : ['jpg', 'jpeg', 'png', 'webp']),
                'max:'.$maxKb,
            ],
        ];
    }
}
