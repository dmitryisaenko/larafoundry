<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Help\Support;

use Dmitryisaenko\LaraFoundry\Email\Support\HtmlSanitizer;
use Dmitryisaenko\LaraFoundry\Help\Models\HelpArticle;
use Dmitryisaenko\LaraFoundry\Help\Models\HelpImage;
use Dmitryisaenko\LaraFoundry\Help\Models\HelpSection;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Every write to the help, and the editor's view of it.
 *
 * The core's admin controller goes through here, and so does a host that edits
 * the help from a console of its own: one place cleans the text, numbers the
 * positions and keeps the pictures' files and records together. Validation rules
 * are exposed as static methods so both callers validate the same way.
 *
 * Every body is cleaned by the HtmlSanitizer ON WRITE and nowhere downstream - which
 * is why nothing but this class (and the guide loader) may write the text columns.
 * The editor is super-admin only, so this is the second line, kept for a hijacked
 * operator account and for an honest paste of broken markup.
 *
 * The `{locale: text}` fields go out as OBJECTS (see `section()` / `article()`):
 * PHP encodes an empty array as `[]`, and `v-model="field[lang]"` on a JS array sets
 * a property JSON.stringify drops - the text would be "saved" and never stored.
 */
final class HelpEditor
{
    /** The longest side a picture may have, in pixels: a screenshot of a 4K screen and then some. */
    private const PICTURE_MAX_SIDE = 8000;

    /** Accepted picture types, read from the CONTENTS (never the name the browser sent). */
    private const PICTURE_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif',
    ];

    public function __construct(private readonly HtmlSanitizer $sanitizer) {}

    // ------------------------------------------------------------------ rules

    /**
     * @return array<string, mixed>
     */
    public static function sectionRules(?HelpSection $section = null): array
    {
        $unique = Rule::unique((new HelpSection)->getTable(), 'key');

        return [
            'key' => ['required', 'string', 'max:64', 'regex:/^[a-z][a-z0-9_]*$/D', $section ? $unique->ignore($section->id) : $unique],
            'title' => ['required', 'array'],
            'title.*' => ['nullable', 'string', 'max:200'],
            'blurb' => ['nullable', 'array'],
            'blurb.*' => ['nullable', 'string', 'max:500'],
            'is_published' => ['boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function articleRules(): array
    {
        return [
            'heading' => ['required', 'array'],
            'heading.*' => ['nullable', 'string', 'max:300'],
            // `present`, not `required`: a new article has no text yet, and `{}` is an
            // empty array to `required` - the heading or the phone text could not be saved.
            'body' => ['present', 'array'],
            'body.*' => ['nullable', 'string', 'max:20000'],
            'body_mobile' => ['nullable', 'array'],
            'body_mobile.*' => ['nullable', 'string', 'max:20000'],
            'is_published' => ['boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function pictureRules(HelpImage $image): array
    {
        return [
            'ref' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9][a-z0-9_-]*$/D', Rule::unique($image->getTable(), 'ref')->ignore($image->id)],
            'caption' => ['nullable', 'array'],
            'caption.*' => ['nullable', 'string', 'max:300'],
            'max_width' => ['nullable', 'integer', 'min:200', 'max:2000'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function uploadRules(): array
    {
        return [
            'image' => ['required', 'file', 'image', 'max:'.(int) config('larafoundry-help.pictures.max_upload_kb', 4096)],
            'ref' => ['nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function orderRules(string $model): array
    {
        return [
            'ids' => ['required', 'array'],
            'ids.*' => ['integer', Rule::exists((new $model)->getTable(), 'id')],
        ];
    }

    // ------------------------------------------------------------------ the editor's view

    /**
     * Every section with every article, published or not. Tens of rows, sent at once.
     *
     * @return list<array<string, mixed>>
     */
    public function tree(): array
    {
        return HelpSection::query()
            ->with('articles')
            ->orderBy('position')
            ->get()
            ->map(fn (HelpSection $section) => $this->section($section))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function section(HelpSection $section): array
    {
        return [
            'id' => $section->id,
            'key' => $section->key,
            'title' => (object) ($section->title_translations ?? []),
            'blurb' => (object) ($section->blurb_translations ?? []),
            'position' => $section->position,
            'is_published' => $section->is_published,
            'articles' => $section->articles->map(fn (HelpArticle $a) => $this->article($a))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function article(HelpArticle $article): array
    {
        return [
            'id' => $article->id,
            'section_id' => $article->help_section_id,
            'heading' => (object) ($article->heading_translations ?? []),
            'body' => (object) ($article->body_translations ?? []),
            'body_mobile' => (object) ($article->mobile_body_translations ?? []),
            'position' => $article->position,
            'is_published' => $article->is_published,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function pictures(): array
    {
        return HelpImage::query()
            ->orderByDesc('id')
            ->get()
            ->map(fn (HelpImage $image) => $this->picture($image))
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function picture(HelpImage $image): array
    {
        return [
            'id' => $image->id,
            'ref' => $image->ref,
            'marker' => $image->marker(),
            'url' => $image->url(),
            'caption' => (object) ($image->caption_translations ?? []),
            'max_width' => $image->max_width,
        ];
    }

    // ------------------------------------------------------------------ sections

    /**
     * @param  array<string, mixed>  $data  validated by {@see sectionRules()}
     */
    public function createSection(array $data): HelpSection
    {
        return HelpSection::query()->create([
            'key' => $data['key'],
            'title_translations' => $this->clean((array) ($data['title'] ?? []), html: false),
            'blurb_translations' => $this->clean((array) ($data['blurb'] ?? []), html: false),
            // At the end, with room after it for an insertion.
            'position' => ((int) HelpSection::query()->max('position')) + 10,
            'is_published' => (bool) ($data['is_published'] ?? true),
        ])->load('articles');
    }

    /**
     * @param  array<string, mixed>  $data  validated by {@see sectionRules()}
     */
    public function updateSection(HelpSection $section, array $data): HelpSection
    {
        $section->update([
            'key' => $data['key'],
            'title_translations' => $this->clean((array) ($data['title'] ?? []), html: false),
            'blurb_translations' => $this->clean((array) ($data['blurb'] ?? []), html: false),
            'is_published' => (bool) ($data['is_published'] ?? $section->is_published),
        ]);

        return $section->fresh('articles') ?? $section;
    }

    /**
     * Deletes the section's articles with it - explicitly, not by trusting the foreign
     * key: an engine that does not enforce it (SQLite without the pragma) would leave
     * articles behind that no tab can ever show again.
     */
    public function deleteSection(HelpSection $section): void
    {
        DB::transaction(function () use ($section): void {
            $section->articles()->delete();
            $section->delete();
        });
    }

    /**
     * The whole order at once - the screen already knows the final arrangement, and a
     * sequence of pairwise moves is a sequence of chances to disagree about it.
     *
     * @param  list<int>  $ids
     */
    public function reorderSections(array $ids): void
    {
        DB::transaction(function () use ($ids): void {
            foreach (array_values($ids) as $at => $id) {
                HelpSection::query()->whereKey($id)->update(['position' => ($at + 1) * 10]);
            }
        });
    }

    // ------------------------------------------------------------------ articles

    /**
     * @param  array<string, mixed>  $data  validated by {@see articleRules()}
     */
    public function createArticle(HelpSection $section, array $data): HelpArticle
    {
        return $section->articles()->create([
            'heading_translations' => $this->clean((array) ($data['heading'] ?? []), html: false),
            'body_translations' => $this->clean((array) ($data['body'] ?? []), html: true),
            'mobile_body_translations' => $this->clean((array) ($data['body_mobile'] ?? []), html: true) ?: null,
            'position' => ((int) $section->articles()->max('position')) + 10,
            'is_published' => (bool) ($data['is_published'] ?? true),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data  validated by {@see articleRules()}
     */
    public function updateArticle(HelpArticle $article, array $data): HelpArticle
    {
        $article->update([
            'heading_translations' => $this->clean((array) ($data['heading'] ?? []), html: false),
            'body_translations' => $this->clean((array) ($data['body'] ?? []), html: true),
            // Kept when the request does not carry the field at all: an editor page
            // loaded before it existed must not wipe a phone text written since.
            'mobile_body_translations' => array_key_exists('body_mobile', $data)
                ? ($this->clean((array) ($data['body_mobile'] ?? []), html: true) ?: null)
                : $article->mobile_body_translations,
            'is_published' => (bool) ($data['is_published'] ?? $article->is_published),
        ]);

        return $article->fresh() ?? $article;
    }

    public function deleteArticle(HelpArticle $article): void
    {
        $article->delete();
    }

    /**
     * @param  list<int>  $ids
     */
    public function reorderArticles(HelpSection $section, array $ids): void
    {
        DB::transaction(function () use ($section, $ids): void {
            foreach (array_values($ids) as $at => $id) {
                // Scoped to this section: an id from another one must not be moved
                // out from under a tab nobody is looking at.
                $section->articles()->whereKey($id)->update(['position' => ($at + 1) * 10]);
            }
        });
    }

    // ------------------------------------------------------------------ pictures

    /**
     * Store an uploaded picture and make its record. The reference is offered, not
     * demanded: a free one close to `$wantedRef` is chosen, and the operator may
     * rename it afterwards.
     *
     * @throws ValidationException when the contents are not one of the four types
     */
    public function uploadPicture(UploadedFile $file, ?string $wantedRef = null): HelpImage
    {
        $ext = self::PICTURE_TYPES[(string) $file->getMimeType()] ?? null;

        if ($ext === null) {
            throw ValidationException::withMessages([
                'image' => __('larafoundry::help.picture_type'),
            ]);
        }

        // Measured before it is decoded: a small, highly compressed file can open out
        // into gigabytes of pixels, and running out of memory is not an exception
        // anything catches.
        $size = @getimagesize((string) $file->getRealPath());

        if (! is_array($size) || $size[0] > self::PICTURE_MAX_SIDE || $size[1] > self::PICTURE_MAX_SIDE) {
            throw ValidationException::withMessages([
                'image' => __('larafoundry::help.picture_size', ['max' => self::PICTURE_MAX_SIDE]),
            ]);
        }

        // Converted before it is named: the conversion decides the extension, and the
        // served Content-Type is derived from it.
        [$bytes, $ext] = WebpPicture::from((string) file_get_contents((string) $file->getRealPath()), $ext);

        $name = Str::uuid()->toString().'.'.$ext;

        $this->disk()->put($this->path($name), $bytes);

        return HelpImage::query()->create([
            'ref' => $this->freeRef((string) $wantedRef),
            'file' => $name,
            'caption_translations' => [],
        ]);
    }

    /**
     * Renaming a reference breaks the texts that use it - allowed, the editor says so.
     *
     * @param  array<string, mixed>  $data  validated by {@see pictureRules()}
     */
    public function updatePicture(HelpImage $image, array $data): HelpImage
    {
        $image->update([
            'ref' => $data['ref'],
            'caption_translations' => $this->clean((array) ($data['caption'] ?? []), html: false),
            'max_width' => $data['max_width'] ?? null,
        ]);

        return $image->fresh() ?? $image;
    }

    /**
     * The file goes with the record: a file left behind on a disk served to everyone,
     * with nothing pointing at it, could never be found again. The texts that used
     * the reference are not rewritten - an unknown marker renders as nothing.
     */
    public function deletePicture(HelpImage $image): void
    {
        $this->disk()->delete($this->path($image->file));

        $image->delete();
    }

    public function disk(): Filesystem
    {
        return Storage::disk((string) config('larafoundry-help.pictures.disk', 'local'));
    }

    /** A stored file's path on the disk. */
    public function path(string $name): string
    {
        $dir = trim((string) config('larafoundry-help.pictures.directory', ''), '/');

        return $dir === '' ? $name : $dir.'/'.$name;
    }

    // ------------------------------------------------------------------ shared

    /**
     * Keep only the help's locales, drop the blanks, clean the text.
     *
     * BLANK MEANS ABSENT, not "translated to nothing": an empty string would win the
     * reader's fallback and show an empty accordion instead of the source text. An
     * editor left untouched hands back `<p></p>`, which is as blank.
     *
     * @param  array<string, mixed>  $field
     * @return array<string, string>
     */
    public function clean(array $field, bool $html): array
    {
        $out = [];

        foreach (HelpText::locales() as $locale) {
            $value = trim(is_string($field[$locale] ?? null) ? $field[$locale] : '');

            if ($value === '') {
                continue;
            }

            $value = $html ? $this->sanitizer->clean($value) : trim(strip_tags($value));

            $blank = $html
                ? ! str_contains($value, '<img') && ! HelpText::hasText($value)
                : $value === '';

            if ($blank) {
                continue;
            }

            $out[$locale] = $value;
        }

        return $out;
    }

    /** A free reference close to the wanted one: three uploads in a row must not collide. */
    private function freeRef(string $wanted): string
    {
        $base = trim(strtolower((string) preg_replace('/[^a-z0-9_-]+/i', '-', $wanted)), '-');

        if ($base === '' || preg_match('/^[a-z0-9]/', $base) !== 1) {
            $base = 'pic';
        }

        $base = substr($base, 0, 48);
        $try = $base;
        $n = 1;

        while (HelpImage::query()->where('ref', $try)->exists()) {
            $try = $base.'-'.(++$n);
        }

        return $try;
    }
}
