<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Media\Contracts;

/**
 * Generates a placeholder avatar for a user/company that has no uploaded image
 * (phase 2.4).
 *
 * The core's default implementation renders initials inline (a data URI), so a
 * missing avatar costs no stored file and never becomes an orphan — the URL is
 * derived from the seed on the fly. A host may rebind this to a different
 * generator (Gravatar-first, a service, a static placeholder) without touching
 * the User/Company accessors that call it.
 */
interface AvatarGenerator
{
    /**
     * A renderable URL (an inline `data:` URI by default) for a placeholder
     * avatar derived from the seed (typically a name).
     *
     * @param  array<string, mixed>  $options  'size', 'shape', 'chars' overrides
     */
    public function url(string $seed, array $options = []): string;
}
