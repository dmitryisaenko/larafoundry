<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Help\Console\Commands;

use Dmitryisaenko\LaraFoundry\Help\Models\HelpArticle;
use Dmitryisaenko\LaraFoundry\Help\Models\HelpSection;
use Dmitryisaenko\LaraFoundry\Help\Support\HelpEditor;
use Dmitryisaenko\LaraFoundry\Help\Support\HelpText;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Load a section's guide from files into the help tables.
 *
 * A guide is written and reviewed as a whole, so it lives as files - one per
 * article, `<guide_path>/<section>/NN-some-words.php`, each returning
 * `['heading' => [locale => text], 'body' => [...], 'mobile' => [...]?]` - and a
 * diff shows exactly which words changed in which language. The editor remains the
 * place for small edits afterwards.
 *
 * The file's NUMBER is the article's position (`03-` -> 30) and the whole of its
 * identity, so a re-run UPDATES the row at that position instead of adding one.
 * Without `--prune` nothing is ever deleted; with it, every article of the section
 * no file claims is removed (listed first). `--dry-run` prints and writes nothing -
 * the right first run on production. All or nothing: one broken file loads nothing.
 *
 * The same cleaning as the editor (it goes through {@see HelpEditor::clean()});
 * markers stay markers and are resolved when the help is read.
 *
 * LANGUAGES. The source language is required. Every other language this installation
 * speaks is either WHOLE in a file (heading, body, and the phone text when the file has
 * one) or absent: a half-translated article is a broken file. An absent language is
 * loaded as absent - its readers get the source text with the help's "not translated"
 * note - and the run lists what is missing, so a guide can go out in Russian today and
 * gain its translations later without a new rule. A file is the whole truth for the
 * languages this installation speaks; languages it does not speak are left as stored,
 * because another installation reading the same tables may speak them
 * ({@see HelpEditor::keepUnlisted()}).
 */
class LoadHelpGuideCommand extends Command
{
    protected $signature = 'larafoundry:help-load-guide
        {section : the help section key, e.g. billing}
        {--path= : the folder of article files (default: <guide_path>/<section>)}
        {--prune : delete the section\'s articles that no file claims}
        {--dry-run : print what would happen and change nothing}';

    protected $description = 'Load a help section\'s articles from files into the help tables';

    public function handle(HelpEditor $editor): int
    {
        $key = (string) $this->argument('section');
        $section = HelpSection::query()->where('key', $key)->first();

        if ($section === null) {
            $this->error("No help section '{$key}'. Create it in the editor first.");

            return self::FAILURE;
        }

        $base = config('larafoundry-help.guide_path') ?: resource_path('help');
        $folder = (string) ($this->option('path') ?: rtrim((string) $base, '/\\').DIRECTORY_SEPARATOR.$key);

        if (! is_dir($folder)) {
            $this->error("No such folder: {$folder}");

            return self::FAILURE;
        }

        $loaded = $this->read($folder);

        if ($loaded === null) {
            return self::FAILURE;
        }

        $articles = $section->articles()->get();

        // Two rows on one position: keyBy would keep one, and the other would be
        // neither updated nor pruned. The position is the identity - refuse.
        $doubled = $articles->countBy('position')->filter(fn (int $n) => $n > 1)->keys();

        if ($doubled->isNotEmpty()) {
            $this->error('Several articles share a position: '.$doubled->implode(', ').'. Reorder them in the editor first.');

            return self::FAILURE;
        }

        $dry = (bool) $this->option('dry-run');
        $prune = (bool) $this->option('prune');
        $existing = $articles->keyBy('position');
        $source = HelpText::sourceLocale();
        $added = 0;
        $updated = 0;

        $write = function () use ($loaded, $section, $existing, $dry, $editor, $source, &$added, &$updated): void {
            foreach ($loaded as $position => $article) {
                $row = $existing->get($position);
                $fields = [
                    'heading_translations' => $editor->keepUnlisted($editor->clean($article['heading'], html: false), $row?->heading_translations),
                    'body_translations' => $editor->keepUnlisted($editor->clean($article['body'], html: true), $row?->body_translations),
                    // Null when the file has none: an article rewritten without a phone
                    // text must not keep showing the old one (in this installation's
                    // languages - see keepUnlisted for the others).
                    'mobile_body_translations' => $editor->keepUnlisted(
                        $article['mobile'] === [] ? [] : $editor->clean($article['mobile'], html: true),
                        $row?->mobile_body_translations,
                    ) ?: null,
                ];

                $heading = $fields['heading_translations'][$source] ?? '';
                $this->line(sprintf('  %s position %d: %s', $row ? 'update' : 'add   ', $position, $heading));

                // A reorder in the editor renumbers positions, and a re-run then
                // overwrites whatever stands there now - say whose text is replaced.
                $was = $row ? (string) ($row->heading_translations[$source] ?? '') : '';

                if ($row && $was !== $heading) {
                    $this->warn(sprintf('      replaces «%s»', $was));
                }

                $row ? $updated++ : $added++;

                if ($dry) {
                    continue;
                }

                // An article hidden in the editor stays hidden; a new one is shown.
                $row
                    ? $row->update($fields)
                    : $section->articles()->create($fields + ['position' => $position, 'is_published' => true]);
            }
        };

        $stray = $existing->reject(fn (HelpArticle $a, int $position) => isset($loaded[$position]));

        if ($dry) {
            $write();
        } else {
            DB::transaction(function () use ($write, $prune, $stray): void {
                $write();

                if ($prune) {
                    $stray->each(fn (HelpArticle $a) => $a->delete());
                }
            });
        }

        foreach ($stray as $position => $article) {
            $this->line(sprintf(
                '  %s position %d: %s',
                $prune ? ($dry ? 'would delete' : 'deleted     ') : 'left alone  ',
                $position,
                $article->heading_translations[$source] ?? '(no heading in '.$source.')',
            ));
        }

        // What readers in each language will get in the source instead, said once per
        // language rather than once per file: twenty-six lines saying "es" are noise.
        $missing = [];

        foreach ($loaded as $article) {
            foreach ($article['missing'] as $locale) {
                $missing[$locale] = ($missing[$locale] ?? 0) + 1;
            }
        }

        foreach ($missing as $locale => $count) {
            $this->warn(sprintf('  not translated into %s: %d of %d articles (readers get %s with a note)', $locale, $count, count($loaded), $source));
        }

        $this->info(sprintf(
            '%s%d added, %d updated, %d %s.',
            $dry ? '[dry run] ' : '',
            $added,
            $updated,
            $stray->count(),
            $prune ? ($dry ? 'to delete' : 'deleted') : 'left alone',
        ));

        return self::SUCCESS;
    }

    /**
     * Read and check every file, or name the first bad one.
     *
     * @return array<int, array{heading: array<string, string>, body: array<string, string>, mobile: array<string, string>, missing: list<string>}>|null
     */
    private function read(string $folder): ?array
    {
        $files = glob(rtrim($folder, '/\\').DIRECTORY_SEPARATOR.'*.php') ?: [];
        sort($files);

        if ($files === []) {
            $this->error("No article files in {$folder}");

            return null;
        }

        $locales = HelpText::locales();
        $source = HelpText::sourceLocale();
        $loaded = [];

        foreach ($files as $file) {
            $name = basename($file);

            if (preg_match('/^(\d{1,3})-[a-z0-9-]+\.php$/', $name, $m) !== 1) {
                $this->error("{$name}: the name must be NN-some-words.php (the number is the position).");

                return null;
            }

            $position = (int) $m[1] * 10;

            if (isset($loaded[$position])) {
                $this->error("{$name}: position {$position} is already taken by another file.");

                return null;
            }

            $data = require $file;

            if (! is_array($data)) {
                $this->error("{$name}: the file must return an array.");

                return null;
            }

            $mobile = $data['mobile'] ?? [];

            if (! is_array($mobile) || ! is_array($data['heading'] ?? null) || ! is_array($data['body'] ?? null)) {
                $this->error("{$name}: 'heading' and 'body' must be arrays of texts by language, and so must 'mobile' when given.");

                return null;
            }

            // The fields a language needs to count as translated: the phone text too,
            // when the file has one - a reader on a phone would otherwise get the
            // computer's text in their language with nothing saying why.
            $fields = $mobile === [] ? ['heading', 'body'] : ['heading', 'body', 'mobile'];
            $has = fn (string $field, string $locale): bool => is_string(($field === 'mobile' ? $mobile : $data[$field])[$locale] ?? null)
                && trim(($field === 'mobile' ? $mobile : $data[$field])[$locale]) !== '';
            $absent = [];

            foreach ($locales as $locale) {
                $given = array_filter($fields, fn (string $field) => $has($field, $locale));

                if (count($given) === count($fields)) {
                    continue;
                }

                if ($locale === $source) {
                    $lacking = implode("', '", array_diff($fields, $given));
                    $this->error("{$name}: '{$lacking}' has no '{$locale}' text, and {$locale} is the source language.");

                    return null;
                }

                if ($given !== []) {
                    $lacking = implode("', '", array_diff($fields, $given));
                    $this->error("{$name}: '{$locale}' is translated only in part - '{$lacking}' is missing.");

                    return null;
                }

                $absent[] = $locale;
            }

            $loaded[$position] = [
                'heading' => $data['heading'],
                'body' => $data['body'],
                'mobile' => $mobile,
                'missing' => $absent,
            ];
        }

        ksort($loaded);

        return $loaded;
    }
}
