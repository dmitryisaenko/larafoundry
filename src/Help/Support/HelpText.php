<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Help\Support;

/**
 * The help's languages, and how one `{locale: text}` field is read in one of them.
 *
 * The operator writes in the SOURCE language and translates afterwards, so for a
 * while a new article exists in one language only. A blank in the reader's language
 * therefore falls back - to the source, then to the app's default, then to anything
 * at all - because an empty accordion under a working heading looks broken, while a
 * text in the wrong language with a note saying so looks merely untranslated.
 */
final class HelpText
{
    /** The language the operator writes in and translates from. */
    public static function sourceLocale(): string
    {
        $source = config('larafoundry-help.source_locale');

        return is_string($source) && $source !== ''
            ? $source
            : (string) config('larafoundry.locale.default', 'en');
    }

    /**
     * The languages a help text is kept in: the app's available locales, the source
     * first (it is where text is written, not just one of the languages).
     *
     * @return list<string>
     */
    public static function locales(): array
    {
        $available = array_values(array_filter(
            (array) config('larafoundry.locale.available', ['en']),
            'is_string',
        ));
        $source = self::sourceLocale();

        return array_values(array_unique([$source, ...$available]));
    }

    /**
     * @param  array<string, mixed>|null  $field
     */
    public static function line(?array $field, string $locale): string
    {
        return self::lineIn($field, $locale)['text'];
    }

    /**
     * The text in `$locale`, or the nearest substitute, and WHICH locale answered -
     * so the screen can tell the reader they are reading a substitute.
     *
     * @param  array<string, mixed>|null  $field
     * @return array{text: string, locale: string}
     */
    public static function lineIn(?array $field, string $locale): array
    {
        if (! is_array($field)) {
            return ['text' => '', 'locale' => $locale];
        }

        $chain = [$locale, self::sourceLocale(), (string) config('larafoundry.locale.default', 'en')];

        foreach (array_unique($chain) as $try) {
            $value = trim((string) ($field[$try] ?? ''));

            if ($value !== '') {
                return ['text' => $value, 'locale' => $try];
            }
        }

        foreach ($field as $code => $value) {
            $value = trim(is_string($value) ? $value : '');

            if ($value !== '') {
                return ['text' => $value, 'locale' => (string) $code];
            }
        }

        return ['text' => '', 'locale' => $locale];
    }

    /**
     * Whether an HTML text says anything. The editor leaves `<p></p>` behind when a
     * text is cleared, and a picture marker alone IS content.
     */
    public static function hasText(?string $html): bool
    {
        $html = (string) $html;

        if (str_contains($html, '[[img:')) {
            return true;
        }

        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim($plain, " \t\n\r\0\x0B\u{00A0}") !== '';
    }
}
