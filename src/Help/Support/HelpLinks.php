<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Help\Support;

/**
 * Links between the articles of a section, laid at the moment the help is READ.
 *
 * Read time on purpose, like the pictures: nothing is written into the stored texts,
 * so a translator never sees a link and every language gets its links from its own
 * words. Two kinds:
 *
 * - **A heading.** Another article's heading met word for word in a text leads to
 *   that article. Once per paragraph or list item: a glossary names the same article
 *   in many items, and each of them should lead there.
 * - **A term.** A glossary is a list whose items read
 *   `<li><strong>Term</strong> - what it is. More: Heading of the article.</li>`,
 *   the last words, after a colon, being another article's heading. Every such item teaches the
 *   section a term, and the term's FIRST mention in each other article leads to the
 *   article the glossary names. A term of several words is matched with its first
 *   letter in either case (it starts a glossary line, and stands mid-sentence in a
 *   text); a one-word term only as a name - in quotes, or an abbreviation in
 *   capitals (see pattern()). An article that
 *   defines terms is a glossary itself and gets no term links - its own items already
 *   lead where each term is explained.
 *
 * Never inside a link, a heading or a picture's caption, and never to the article
 * the text belongs to. The link is a query of the reader's page,
 * `?section=<key>&article=<n>` (counted from 1 among the published articles), so it
 * works on whichever page shows the help; `data-help-section` / `data-help-article`
 * let a page that keeps every text in hand switch without a request.
 */
final class HelpLinks
{
    /** Shorter headings and terms are left alone: they are common words, not names. */
    private const MIN_LENGTH = 3;

    /** Inside these nothing is linked. */
    private const SKIP = ['a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'figure', 'figcaption', 'button', 'code', 'pre'];

    /** A heading is linked once per one of these. */
    private const BLOCKS = ['p', 'li', 'td', 'th', 'dd', 'dt', 'blockquote'];

    /**
     * Lay the links in a tree of pages (the {@see HelpBook::pages()} shape).
     *
     * @param  array<string, mixed>  $pages
     * @return array<string, mixed>
     */
    public function link(array $pages): array
    {
        foreach ($pages as $key => $page) {
            $items = array_values($page['items'] ?? []);
            $headings = $this->headings($items);

            if ($headings === []) {
                continue;
            }

            [$terms, $glossaries, $written] = $this->terms($items, $headings);
            $pattern = $this->pattern(array_keys($headings), $written);

            foreach ($items as $at => $item) {
                $number = $at + 1;
                $ownTerms = isset($glossaries[$number]) ? [] : $terms;

                foreach (['body', 'body_mobile'] as $field) {
                    if (! is_string($item[$field] ?? null) || $item[$field] === '') {
                        continue;
                    }

                    $pages[$key]['items'][$at][$field] = $this->inBody(
                        $item[$field], (string) $key, $number, $pattern, $headings, $ownTerms,
                    );
                }
            }
        }

        return $pages;
    }

    /**
     * Every article's heading, as it stands in a text (escaped) => its number.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, int>
     */
    private function headings(array $items): array
    {
        $headings = [];

        foreach ($items as $at => $item) {
            $heading = trim((string) ($item['heading'] ?? ''));

            if (mb_strlen($heading) >= self::MIN_LENGTH) {
                $headings[self::escaped($heading)] ??= $at + 1;
            }
        }

        return $headings;
    }

    /**
     * The terms the section's glossaries define: term (first letter lowered, escaped)
     * => the number of the article named at the end of its item; and which articles
     * are glossaries; and each term as the glossary writes it (escaped).
     *
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, int>  $headings
     * @return array{0: array<string, int>, 1: array<int, true>, 2: array<string, string>}
     */
    private function terms(array $items, array $headings): array
    {
        $byText = [];

        foreach ($headings as $escaped => $number) {
            $byText[html_entity_decode($escaped, ENT_QUOTES | ENT_HTML5, 'UTF-8')] = $number;
        }

        // Longest first: a heading that ends another must not be taken for it.
        uksort($byText, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        $terms = [];
        $glossaries = [];
        $written = [];

        foreach ($items as $at => $item) {
            foreach (['body', 'body_mobile'] as $field) {
                if (! is_string($item[$field] ?? null)) {
                    continue;
                }

                preg_match_all('/<li(?:\s[^>]*)?>\s*<strong>(.*?)<\/strong>(.*?)<\/li>/su', $item[$field], $found, PREG_SET_ORDER);

                foreach ($found as $m) {
                    $term = self::plain($m[1]);
                    $rest = rtrim(self::plain($m[2]), " \t\n.!");

                    if (mb_strlen($term) < self::MIN_LENGTH) {
                        continue;
                    }

                    foreach ($byText as $heading => $number) {
                        // After a colon ("More: Heading"), not merely at the end: an ordinary
                        // list item that happens to close on an article's name ("as the article
                        // Prices says") must not turn its article into a glossary.
                        if (preg_match('/:\s*'.preg_quote($heading, '/').'$/u', $rest) === 1) {
                            $glossaries[$at + 1] = true;
                            $key = self::escaped(self::lowerFirst($term));
                            $terms[$key] ??= $number;
                            $written[$key] ??= self::escaped($term);

                            break;
                        }
                    }
                }
            }
        }

        return [$terms, $glossaries, $written];
    }

    /**
     * One pattern for every heading (as written) and every term (first letter in
     * either case), longest first, whole words only.
     *
     * @param  list<string>  $headings
     * @param  array<string, string>  $terms  term (first letter lowered) => as written
     */
    private function pattern(array $headings, array $terms): string
    {
        $alternatives = [];

        foreach ($headings as $heading) {
            $alternatives[$heading] = preg_quote($heading, '/');
        }

        foreach ($terms as $term => $written) {
            if (isset($alternatives[$term])) {
                continue;
            }

            // ⚠️⚠️ A ONE-WORD TERM ONLY AS A NAME: in quotes, as a text names a tab or a
            // button («Level»), or an abbreviation in capitals (SOS). A single word is ordinary
            // speech as often as it is the name, and a capital letter does not tell them apart
            // - a sentence starts with one. Measured on the Lorevid guide (2026-11-22):
            // "the purchase button" led to the Purchases tab, "Figures: views and likes" to a
            // quiz puzzle called Figures, "a Level lasts 60 seconds" in a game to the A1-C2
            // levels.
            if (! str_contains($written, ' ')) {
                $alternatives[$term] = self::isAbbreviation($written)
                    ? preg_quote($written, '/')
                    : '(?<=[«"“„])'.preg_quote($written, '/').'(?=[»"”“])';

                continue;
            }

            $first = mb_substr($term, 0, 1);
            $upper = mb_strtoupper($first);
            $head = $upper === $first ? preg_quote($first, '/') : '['.preg_quote($first.$upper, '/').']';

            $alternatives[$term] = $head.preg_quote(mb_substr($term, 1), '/');
        }

        uksort($alternatives, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));

        return '/(?<![\p{L}\p{N}_])(?:'.implode('|', $alternatives).')(?![\p{L}\p{N}_])/u';
    }

    /**
     * @param  array<string, int>  $headings
     * @param  array<string, int>  $terms
     */
    private function inBody(string $body, string $section, int $number, string $pattern, array $headings, array $terms): string
    {
        // Text between tags only, as the pictures do: the sanitizer escapes `<` and `>`
        // in attribute values, so a split on tags is exact.
        $parts = preg_split('/(<[^>]*>)/', $body, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$body];
        $skip = 0;
        $headingsHere = [];
        $termsUsed = [];

        foreach ($parts as $at => $part) {
            if ($part === '') {
                continue;
            }

            if ($part[0] === '<') {
                if (preg_match('/^<(\/?)([a-z0-9]+)/i', $part, $tag) === 1) {
                    $name = strtolower($tag[2]);
                    $closing = $tag[1] === '/';

                    if (in_array($name, self::SKIP, true)) {
                        $skip = max(0, $skip + ($closing ? -1 : 1));
                    } elseif (! $closing && in_array($name, self::BLOCKS, true)) {
                        $headingsHere = [];
                    }
                }

                continue;
            }

            if ($skip > 0) {
                continue;
            }

            // `?? $part`: on bytes that are not UTF-8 the call gives null, and the paragraph read as empty.
            $parts[$at] = preg_replace_callback(
                $pattern,
                function (array $m) use ($section, $number, $headings, $terms, &$headingsHere, &$termsUsed): string {
                    $text = $m[0];

                    if (isset($headings[$text])) {
                        $target = $headings[$text];

                        if ($target === $number || isset($headingsHere[$target])) {
                            return $text;
                        }

                        $headingsHere[$target] = true;

                        return self::anchor($text, $section, $target);
                    }

                    $term = self::lowerFirst($text);
                    $target = $terms[$term] ?? null;

                    if ($target === null || $target === $number || isset($termsUsed[$term])) {
                        return $text;
                    }

                    $termsUsed[$term] = true;

                    return self::anchor($text, $section, $target);
                },
                $part,
            ) ?? $part;
        }

        return implode('', $parts);
    }

    private static function anchor(string $text, string $section, int $target): string
    {
        $href = '?section='.rawurlencode($section).'&amp;article='.$target;

        return '<a href="'.$href.'" class="help-link" data-help-section="'.e($section).'" data-help-article="'.$target.'">'.$text.'</a>';
    }

    /** As a text node holds it. */
    private static function escaped(string $text): string
    {
        return htmlspecialchars($text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8', false);
    }

    private static function plain(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
    }

    /** SOS, AI: letters, every one of them a capital. */
    private static function isAbbreviation(string $word): bool
    {
        return preg_match('/\p{L}/u', $word) === 1 && mb_strtoupper($word) === $word;
    }

    private static function lowerFirst(string $text): string
    {
        return mb_strtolower(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
