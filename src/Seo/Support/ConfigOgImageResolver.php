<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Seo\Support;

use Dmitryisaenko\LaraFoundry\Media\Contracts\MediaStorage;
use Dmitryisaenko\LaraFoundry\Seo\Contracts\OgImageResolver;

/**
 * The core's default OG image resolver — a THIN, config-only seam (phase 5.2).
 *
 * Reads `larafoundry-seo.og.default_image`. An absolute http(s) URL is returned
 * as-is; a relative path is resolved to a URL through the core's media-storage
 * contract ({@see MediaStorage::url()}), so a host that rebinds the disk/CDN
 * resolves the OG image the same way as every other media URL, and a host can
 * just drop an image on its public disk and point config at the path.
 * There is NO image processing here — a host or add-on that wants a rendered,
 * per-page image rebinds the {@see OgImageResolver} contract.
 */
class ConfigOgImageResolver implements OgImageResolver
{
    public function default(): ?string
    {
        $configured = config('larafoundry-seo.og.default_image');

        if (! is_string($configured) || $configured === '') {
            return null;
        }

        // Already an absolute http(s) URL — use it verbatim.
        if (Url::isHttp($configured)) {
            return $configured;
        }

        // A relative path: resolve it to a public URL via the core media-storage
        // contract (honours a host that rebinds MediaStorage to a custom disk/CDN).
        return app(MediaStorage::class)->url($configured);
    }
}
