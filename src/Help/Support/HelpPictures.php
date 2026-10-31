<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Help\Support;

use Dmitryisaenko\LaraFoundry\Help\Contracts\HelpPictureOptions;
use Dmitryisaenko\LaraFoundry\Help\Models\HelpImage;

/**
 * Turns `[[img:ref]]` into a picture, at the moment the help is READ.
 *
 * Resolved at read time on purpose: baking the address into the stored HTML would
 * freeze the picture of the day the text was written, and a retaken screenshot
 * would have to be re-inserted into every translation.
 *
 * A marker may carry attributes, in any order: `[[img:ref|width=400|align=left]]`.
 * `width` is a number (200..2000) or `small` / `medium` / `large` / `full` (no
 * ceiling); `align` is `left` / `center` / `right`. A value that makes no sense is
 * dropped, never fatal - these are typed by hand into a body, where nothing checks
 * them. The width the TEXT asks for beats the picture's own, which beats the
 * default: one screenshot is used in two articles at two sizes.
 *
 * A reference nobody defined renders NOTHING rather than the raw marker.
 */
final class HelpPictures
{
    public const MARKER = '/\[\[img:([a-z0-9][a-z0-9_-]{0,63})((?:\|[a-z]+=[a-z0-9]+)*)\]\]/i';

    /** The article texts a marker may sit in. */
    private const BODIES = ['body', 'body_mobile'];

    /** Named widths, so `width=small` means the same 300 in every article. */
    private const WORDS = [
        'small' => 300,
        'medium' => 500,
        'large' => 700,
    ];

    public function __construct(private readonly HelpPictureOptions $options) {}

    /** A width within 200..2000, or null for anything else. */
    public static function boundedWidth(int $width): ?int
    {
        return $width >= 200 && $width <= 2000 ? $width : null;
    }

    /**
     * Replace every marker in a tree of pages (the {@see HelpBook::pages()} shape).
     *
     * @param  array<string, mixed>  $pages
     * @return array<string, mixed>
     */
    public function fill(array $pages, string $locale): array
    {
        $images = HelpImage::query()->get()->keyBy(fn (HelpImage $i) => strtolower($i->ref))->all();
        $default = $this->options->defaultWidth();

        foreach ($pages as $key => $page) {
            foreach (($page['items'] ?? []) as $at => $item) {
                foreach (self::BODIES as $field) {
                    if (! is_string($item[$field] ?? null)) {
                        continue;
                    }

                    $pages[$key]['items'][$at][$field] = $this->inBody($item[$field], $images, $locale, $default);
                }
            }
        }

        return $pages;
    }

    /**
     * @param  array<string, HelpImage>  $images
     */
    private function inBody(string $body, array $images, string $locale, int $default): string
    {
        // Text between tags only: a marker kept in an attribute (a link's `title`
        // survives the sanitizer) would otherwise put a figure, quotes and all, inside
        // that attribute and break the tag open. The sanitizer escapes `<` and `>` in
        // attribute values, so a split on tags is exact.
        // A marker alone in its paragraph (how the editor inserts one) takes the
        // paragraph with it: a figure may not sit in a `<p>`, and the browser would
        // close it early and leave an empty paragraph above and below the picture.
        $body = (string) preg_replace('/<p>\s*('.substr(self::MARKER, 1, -2).')\s*<\/p>/i', '$1', $body);
        $parts = preg_split('/(<[^>]*>)/', $body, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$body];

        foreach ($parts as $at => $part) {
            if ($part !== '' && $part[0] !== '<') {
                $parts[$at] = $this->inText($part, $images, $locale, $default);
            }
        }

        return implode('', $parts);
    }

    /**
     * @param  array<string, HelpImage>  $images
     */
    private function inText(string $body, array $images, string $locale, int $default): string
    {
        return (string) preg_replace_callback(
            self::MARKER,
            function (array $m) use ($images, $locale, $default): string {
                $image = $images[strtolower($m[1])] ?? null;

                if ($image === null) {
                    return '';
                }

                $caption = HelpText::line($image->caption_translations, $locale);
                $said = self::attributes($m[2] ?? '');

                // `array_key_exists`, not `??`: `width=full` is stored as null ("no
                // ceiling"), which `??` would read as "the text said nothing".
                $width = array_key_exists('width', $said)
                    ? $said['width']
                    : ($image->max_width ?: $default);

                // A ceiling, not a size: with `width: 100%` a phone draws it edge to
                // edge and a desktop stops here.
                $style = $width === null ? '' : ' style="max-width:'.$width.'px"';

                $class = 'help-pic';

                if (isset($said['align'])) {
                    $class .= ' is-'.$said['align'];
                }

                $figure = '<figure class="'.$class.'"'.$style.'>'
                    .'<img src="'.e($image->url()).'" alt="'.e($caption).'">';

                if ($caption !== '') {
                    $figure .= '<figcaption>'.e($caption).'</figcaption>';
                }

                return $figure.'</figure>';
            },
            $body,
        );
    }

    /**
     * @return array{width?: int|null, align?: string}
     */
    private static function attributes(string $tail): array
    {
        if ($tail === '') {
            return [];
        }

        $said = [];

        foreach (explode('|', trim($tail, '|')) as $pair) {
            [$name, $value] = array_pad(explode('=', $pair, 2), 2, '');

            $name = strtolower($name);
            $value = strtolower($value);

            if ($name === 'width') {
                if ($value === 'full') {
                    $said['width'] = null;

                    continue;
                }

                $width = self::boundedWidth(self::WORDS[$value] ?? (ctype_digit($value) ? (int) $value : 0));

                if ($width !== null) {
                    $said['width'] = $width;
                }

                continue;
            }

            if ($name === 'align' && in_array($value, ['left', 'center', 'right'], true)) {
                $said['align'] = $value;
            }
        }

        return $said;
    }
}
