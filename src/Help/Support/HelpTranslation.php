<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Help\Support;

use Dmitryisaenko\LaraFoundry\Help\Contracts\HelpTranslator;
use Dmitryisaenko\LaraFoundry\Help\Exceptions\HelpTranslationRefused;

/**
 * The editor's "translate" button: whether there is one, and the checked answer.
 *
 * The core does not translate - a host binds {@see HelpTranslator}. What the core
 * DOES own is its picture markers, so it checks every answer for them: a model that
 * drops `[[img:ref]]` from a Ukrainian text drops a screenshot only a Ukrainian
 * reader would miss.
 */
final class HelpTranslation
{
    public function available(): bool
    {
        return app()->bound(HelpTranslator::class);
    }

    /**
     * @throws HelpTranslationRefused when a picture marker of the source is missing
     */
    public function translate(string $text, string $to): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        $answer = app(HelpTranslator::class)->translate($text, $to, HelpText::sourceLocale());

        $this->keepsThePictures($text, $answer);

        return $answer;
    }

    /**
     * Counted, not compared as sets: keeping one of two copies of a picture still
     * loses one. Compared in lower case, as the pictures are looked up.
     */
    private function keepsThePictures(string $source, string $answer): void
    {
        preg_match_all(HelpPictures::MARKER, $source, $before);
        preg_match_all(HelpPictures::MARKER, $answer, $after);

        $had = array_count_values(array_map('strtolower', $before[0]));
        $kept = array_count_values(array_map('strtolower', $after[0]));

        $lost = array_keys(array_filter(
            $had,
            fn (int $n, string $marker) => ($kept[$marker] ?? 0) < $n,
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($lost !== []) {
            throw new HelpTranslationRefused(__('larafoundry::help.lost_pictures', ['markers' => implode(', ', $lost)]));
        }
    }
}
