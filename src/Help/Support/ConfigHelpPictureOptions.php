<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Help\Support;

use Dmitryisaenko\LaraFoundry\Help\Contracts\HelpPictureOptions;

/**
 * The default picture options: the core's own picture route and the configured
 * default width (bounded - a zero typed into a config would make every picture
 * invisible).
 */
final class ConfigHelpPictureOptions implements HelpPictureOptions
{
    public function url(string $file): string
    {
        return route('larafoundry.help.picture', ['name' => $file]);
    }

    public function defaultWidth(): int
    {
        $width = (int) config('larafoundry-help.pictures.default_width', 700);

        return HelpPictures::boundedWidth($width) ?? 700;
    }
}
