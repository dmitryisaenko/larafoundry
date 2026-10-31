<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Help\Contracts;

use Dmitryisaenko\LaraFoundry\Help\Exceptions\HelpTranslationRefused;

/**
 * Translates one help text (a heading or an HTML body) out of the source language.
 *
 * NOT bound by default: the core knows nothing about machine translation, so until
 * a host binds an implementation the editor shows no "translate" button and the
 * endpoint answers 404. The answer is shown to the operator and saved only when
 * they press save - a translation is never published on its own.
 *
 * The core checks every answer for the `[[img:ref]]` markers the source had; a host
 * that adds markers of its own (see {@see HelpTextFiller}) checks those itself and
 * throws {@see HelpTranslationRefused} when one was lost.
 */
interface HelpTranslator
{
    /**
     * @param  string  $text  the text in `$from`
     * @param  string  $to  a locale of `larafoundry.locale.available`
     *
     * @throws HelpTranslationRefused when the answer cannot be accepted
     */
    public function translate(string $text, string $to, string $from): string;
}
