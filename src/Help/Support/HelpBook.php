<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Help\Support;

use Closure;
use Dmitryisaenko\LaraFoundry\Help\Contracts\HelpTextFiller;
use Dmitryisaenko\LaraFoundry\Help\Models\HelpArticle;
use Dmitryisaenko\LaraFoundry\Help\Models\HelpSection;

/**
 * The help, ready to show: the published sections and articles in one language,
 * with the host's markers filled and the pictures resolved.
 *
 * The one reader of the help tables - the core's page uses it, and so does a host
 * that draws the help on screens of its own. Shape (public API):
 *
 *     key => [
 *         'title' => string, 'blurb' => string,
 *         'items' => [[
 *             'heading' => string,
 *             'body' => string,                 // HTML, cleaned on write
 *             'body_locale' => ?string,         // null = the reader's language; else the substitute's
 *             'body_mobile' => ?string,         // the phone's own text, null when none is written
 *             'body_mobile_locale' => ?string,
 *         ], ...],
 *     ]
 */
final class HelpBook
{
    /** @var list<HelpTextFiller|Closure(): HelpTextFiller> */
    private array $fillers = [];

    public function __construct(private readonly HelpPictures $pictures) {}

    /**
     * A filler, or a factory making one. A factory is called afresh for every reading of the help,
     * so a filler that looks its values up once (say, today's prices) keeps them for one page and
     * never past it - not even in a process that serves many requests or jobs.
     *
     * @param  HelpTextFiller|Closure(): HelpTextFiller  $filler
     */
    public function addFiller(HelpTextFiller|Closure $filler): self
    {
        $this->fillers[] = $filler;

        return $this;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function pages(string $locale): array
    {
        $sections = HelpSection::query()
            ->with(['articles' => fn ($q) => $q->where('is_published', true)])
            ->where('is_published', true)
            ->orderBy('position')
            ->get();

        $pages = [];

        foreach ($sections as $section) {
            $pages[$section->key] = [
                'title' => HelpText::line($section->title_translations, $locale),
                'blurb' => HelpText::line($section->blurb_translations, $locale),
                'items' => $section->articles
                    ->map(fn (HelpArticle $article) => $this->item($article, $locale))
                    ->values()
                    ->all(),
            ];
        }

        return $this->pictures->fill($this->filled($pages, $locale), $locale);
    }

    /**
     * @return array<string, mixed>
     */
    private function item(HelpArticle $article, string $locale): array
    {
        $body = HelpText::lineIn($article->body_translations, $locale);
        $mobile = HelpText::lineIn($article->mobile_body_translations, $locale);
        // A phone text found only in a substitute language loses to the reader's own
        // full text: the phone then shows that, rather than a stranger's language.
        $hasMobile = HelpText::hasText($mobile['text'])
            && ($mobile['locale'] === $locale || $body['locale'] !== $locale);

        return [
            'heading' => HelpText::line($article->heading_translations, $locale),
            'body' => $body['text'],
            'body_locale' => $body['locale'] === $locale ? null : $body['locale'],
            'body_mobile' => $hasMobile ? $mobile['text'] : null,
            'body_mobile_locale' => $hasMobile && $mobile['locale'] !== $locale ? $mobile['locale'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $pages
     * @return array<string, mixed>
     */
    private function filled(array $pages, string $locale): array
    {
        if ($this->fillers === []) {
            return $pages;
        }

        // This reading's fillers: a factory makes a fresh one each time (see addFiller()).
        $fillers = array_map(
            fn (HelpTextFiller|Closure $filler): HelpTextFiller => $filler instanceof Closure ? $filler() : $filler,
            $this->fillers,
        );

        $fill = function (string $text) use ($fillers, $locale): string {
            foreach ($fillers as $filler) {
                $text = $filler->fill($text, $locale);
            }

            return $text;
        };

        foreach ($pages as $key => $page) {
            foreach (['title', 'blurb'] as $field) {
                $pages[$key][$field] = $fill($page[$field]);
            }

            foreach ($page['items'] as $at => $item) {
                foreach (['heading', 'body', 'body_mobile'] as $field) {
                    if (is_string($item[$field])) {
                        $pages[$key]['items'][$at][$field] = $fill($item[$field]);
                    }
                }
            }
        }

        return $pages;
    }
}
