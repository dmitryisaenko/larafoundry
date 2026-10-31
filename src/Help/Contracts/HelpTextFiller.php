<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Help\Contracts;

/**
 * Fills a host's own markers into the help as it is READ (e.g. `:price_per_min`
 * becoming today's price), so the stored text never freezes the value of the day it
 * was written.
 *
 * Registered with `HelpBook::addFiller()`; every filler sees every text the reader
 * gets (titles, blurbs, headings, both bodies). Runs before the core resolves its
 * `[[img:ref]]` markers.
 */
interface HelpTextFiller
{
    public function fill(string $text, string $locale): string;
}
