<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Help\Contracts;

use Dmitryisaenko\LaraFoundry\Help\Support\ConfigHelpPictureOptions;

/**
 * Where a help picture is fetched from, and how wide it is drawn on a desktop when
 * neither the text nor the picture says. The default ({@see ConfigHelpPictureOptions})
 * answers the core's own route and `larafoundry-help.pictures.default_width`; a host
 * rebinds it when the pictures are served from another address or the width is a
 * setting it edits at runtime.
 */
interface HelpPictureOptions
{
    /** The address of an uploaded file, by its stored name. */
    public function url(string $file): string;

    /** The desktop ceiling, in CSS pixels, of a picture that sets none. */
    public function defaultWidth(): int;
}
