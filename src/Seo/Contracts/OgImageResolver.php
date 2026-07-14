<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Seo\Contracts;

/**
 * Resolves the default Open Graph / Twitter card image (phase 5.2).
 *
 * The core's default implementation is a THIN seam: it returns the configured
 * static image (a media-disk path or an absolute URL) and does NO image
 * processing — a missing configuration yields null and no image tag is emitted.
 * A host or the paid add-on may rebind this contract to a generator that renders
 * a per-page image on the fly, without touching the SeoManager that calls it.
 */
interface OgImageResolver
{
    /**
     * An absolute URL for the default OG image, or null when none is configured.
     */
    public function default(): ?string;
}
