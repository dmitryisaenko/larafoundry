<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Media\Http\Requests\Concerns;

/**
 * Reusable file-upload validation rules driven by the media config (phase 2.4).
 *
 * A FormRequest mixes this in and composes the rules into its own `rules()`, so
 * the size ceiling and accepted image types come from one place
 * (`larafoundry-media`) and a host tuning the config tunes every upload. The
 * config `max_upload_kb` is the hard ceiling that backs the per-request `max:`
 * rule, so an oversized file is rejected even where a rule was forgotten (recon
 * finding #4).
 */
trait ValidatesUploadedFile
{
    /**
     * Validation rules for an image upload (avatars, logos).
     *
     * @return array<int, mixed>
     */
    protected function imageUploadRules(bool $required = false): array
    {
        $maxKb = (int) config('larafoundry-media.max_upload_kb', 5120);

        /** @var array<int, string> $mimes */
        $mimes = (array) config('larafoundry-media.image_mimes', ['jpg', 'jpeg', 'png', 'webp']);

        $maxDimension = (int) config('larafoundry-media.max_image_dimension', 4000);

        return array_filter([
            $required ? 'required' : 'nullable',
            'image',
            'mimes:'.implode(',', $mimes),
            'max:'.$maxKb,
            // Guards against a tiny-bytes / huge-pixels decompression bomb.
            'dimensions:max_width='.$maxDimension.',max_height='.$maxDimension,
        ]);
    }

    /**
     * Validation rules for a generic (non-image) file upload.
     *
     * @return array<int, mixed>
     */
    protected function fileUploadRules(bool $required = false): array
    {
        $maxKb = (int) config('larafoundry-media.max_upload_kb', 5120);

        return array_filter([
            $required ? 'required' : 'nullable',
            'file',
            'max:'.$maxKb,
        ]);
    }
}
