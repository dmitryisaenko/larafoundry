# Help

The help module (v0.48.0) is an in-app help centre: **sections** (a tab each) holding ordered **articles** (an accordion each), every text kept in every available locale, edited by the super-admin in the admin section and read by everyone, guests included. Pictures are records referenced from a text as `[[img:ref]]`, so a retaken screenshot is one upload rather than an edit of every translation. An article may carry a second text written for a phone.

The core does not translate. A host that wants a "Translate" button binds a translator; the core checks every answer and never saves one on its own.

## Contents

- [Install](#install)
- [Configuration](#configuration)
- [Usage](#usage)
- [API reference](#api-reference)
- [Security notes](#security-notes)
- [Host integration](#host-integration)
- [Testing](#testing)

## Install

The module ships with the core package (`src/Help/`). Its three tables are created by the core's migrations regardless of configuration:

- `larafoundry_help_sections` - `key` (the address, unique), `title_translations`, `blurb_translations`, `position`, `is_published`
- `larafoundry_help_articles` - `help_section_id`, `heading_translations`, `body_translations`, `mobile_body_translations` (nullable), `position`, `is_published`
- `larafoundry_help_images` - `ref` (unique), `file` (a uuid name), `caption_translations`, `max_width`

```bash
php artisan migrate
```

The two pages (`Help/Index`, `Admin/Help/Editor`) resolve from the core (`resources/js/pages.js`) or are published with `larafoundry-pages`.

**The editor needs Tiptap.** The writing surface (`components/help/HelpRichText.vue`) is built on Tiptap, which the core does not install and keeps out of the barrel. A host that shows the core's help editor - or resolves pages through `resolvePage()`, which bundles every core page - adds:

```bash
npm install @tiptap/vue-3 @tiptap/starter-kit @tiptap/extension-image @tiptap/extension-text-align
```

## Configuration

`config/larafoundry-help.php` (publish with `--tag=larafoundry-help-config`):

| Key | Default | Meaning |
|-----|---------|---------|
| `source_locale` | `null` (= `larafoundry.locale.default`) | The language the operator writes in. Translations are made from it; a reader whose language has no text yet is shown this one, with a note. |
| `reader.enabled` | `true` | The public page. Off - its route is **not registered**, so the path stays free for a host's own help page. |
| `reader.path` | `help` | Where the public page lives. |
| `admin.enabled` | `true` | The editor at `/admin/help-articles` and its admin-menu entry. Off - neither is registered. |
| `pictures.disk` / `pictures.directory` | `local` / `larafoundry-help` | Where uploads are stored. They are served by the core's own route, never by the disk's URL, so a private disk works. |
| `pictures.default_width` | `700` | The desktop ceiling of a picture that sets none (200..2000). |
| `pictures.max_upload_kb` | `4096` | Upload limit. |
| `guide_path` | `null` (= `resource_path('help')`) | Where `larafoundry:help-load-guide` looks for article files. |

The help's languages are `larafoundry.locale.available`, the source first.

## Usage

### Reading

`/help` shows the published sections; `?section=billing` opens that tab (an unknown value opens the first). No published section answers 404. A host reading the help for screens of its own calls the reader:

```php
use Dmitryisaenko\LaraFoundry\Help\Support\HelpBook;

$pages = app(HelpBook::class)->pages(app()->getLocale());
```

The shape:

```php
'billing' => [
    'title' => 'Billing', 'blurb' => '...',
    'items' => [[
        'heading' => '...',
        'body' => '<p>...</p>',          // HTML, cleaned on write
        'body_locale' => null,           // or the substitute language's code
        'body_mobile' => null,           // the phone's own text, when written
        'body_mobile_locale' => null,
    ]],
],
```

A text missing in the reader's language falls back to the source language, then to the app's default, then to any language at all - and `body_locale` says which one answered.

### Pictures

Upload a picture in the editor (a button on the Pictures tab, or the picture button in an article's toolbar); it becomes a record with a free reference close to the file name and the text gets `[[img:ref]]`. Rename the reference, give it a caption per language and a desktop ceiling on the Pictures tab. A marker may carry attributes in any order:

```
[[img:menu|width=400|align=left]]
```

`width`: 200..2000, or `small` (300), `medium` (500), `large` (700), `full` (no ceiling). `align`: `left`, `center`, `right`. The text's width beats the picture's own, which beats the default. A value that makes no sense is ignored, and a reference nobody defined renders nothing. On a phone a picture is always full width.

A png or jpeg upload is re-encoded as webp when that is smaller (gif and webp are kept).

### Host markers (fillers)

A host fills its own markers into the help as it is read - e.g. a price that must always be today's:

```php
use Dmitryisaenko\LaraFoundry\Help\Contracts\HelpTextFiller;
use Dmitryisaenko\LaraFoundry\Help\Support\HelpBook;

class PriceFiller implements HelpTextFiller
{
    public function fill(string $text, string $locale): string
    {
        return str_replace(':per_minute', (string) Prices::perMinute(), $text);
    }
}

// a service provider's boot()
app(HelpBook::class)->addFiller(new PriceFiller);
```

Fillers run over every text (titles, blurbs, headings, both bodies), before the pictures are resolved. ⚠️ They run **after** the texts were cleaned, and the bodies go onto the page as HTML: a filler must escape every value it does not control itself.

A filler that looks its values up once - so a page with sixty markers does not read the settings sixty times - is registered as a **factory**: it is called afresh for every reading of the help, so the values live for one page and never longer, even in a long-lived worker.

```php
app(HelpBook::class)->addFiller(fn () => new PriceFiller);
```

### Translation

Bind a translator and the editor shows "Translate" on every language but the source:

```php
use Dmitryisaenko\LaraFoundry\Help\Contracts\HelpTranslator;

$this->app->bind(HelpTranslator::class, MyTranslator::class);
```

The answer is put into the field and saved only when the operator presses save. The core refuses an answer that lost a `[[img:...]]` marker of the source (counted, so dropping one of two copies counts). A translator that has markers of its own to protect throws `Help\Exceptions\HelpTranslationRefused` - its message is shown to the operator as is.

### Loading a guide from files

A guide written and reviewed as a whole lives as files, one per article:

```
resources/help/<section>/01-getting-started.php
resources/help/<section>/02-billing.php
```

```php
return [
    'heading' => ['en' => '...', 'uk' => '...'],
    'body'    => ['en' => '<p>...</p>', 'uk' => '<p>...</p>'],   // every language
    'mobile'  => ['en' => '<p>...</p>', 'uk' => '<p>...</p>'],   // optional, all or none
];
```

```bash
php artisan larafoundry:help-load-guide billing --dry-run
php artisan larafoundry:help-load-guide billing
php artisan larafoundry:help-load-guide billing --prune   # also delete articles no file claims
```

The number is the article's position (`03-` is 30) and its identity: a re-run updates in place. Without `--prune` nothing is deleted. One broken file loads nothing. The section must exist (create it in the editor).

## API reference

### `Help\Support\HelpBook` (singleton)

| Method | Purpose |
|--------|---------|
| `pages(string $locale): array` | The published help, filled and with pictures resolved (shape above). |
| `addFiller(HelpTextFiller\|Closure $filler): self` | Register a host filler, or a factory called afresh for every reading. |

### `Help\Support\HelpEditor` (singleton) - every write

| Method | Purpose |
|--------|---------|
| `sectionRules(?HelpSection)`, `articleRules()`, `pictureRules(HelpImage)`, `uploadRules()`, `orderRules(string $model)` | Validation, shared by the core's editor and a host's own. |
| `tree()`, `section()`, `article()`, `pictures()`, `picture()` | The editor's view (text fields as objects). |
| `createSection`, `updateSection`, `deleteSection`, `reorderSections(list<int>)` | Sections (deleting one deletes its articles). |
| `createArticle`, `updateArticle`, `deleteArticle`, `reorderArticles(HelpSection, list<int>)` | Articles (`updateArticle` keeps the phone text when the data has no `body_mobile`). |
| `uploadPicture(UploadedFile, ?string $wantedRef)`, `updatePicture`, `deletePicture` | Pictures (the file goes with the record). |
| `clean(array $field, bool $html)` | The help's locales only, blanks dropped, HTML sanitized. |
| `disk()`, `path(string $name)` | Where a stored picture lives. |

### Contracts

| Contract | Default | Purpose |
|----------|---------|---------|
| `Help\Contracts\HelpTranslator` | not bound | `translate(string $text, string $to, string $from): string` |
| `Help\Contracts\HelpTextFiller` | - | `fill(string $text, string $locale): string`, registered on `HelpBook` |
| `Help\Contracts\HelpPictureOptions` | `ConfigHelpPictureOptions` | `url(string $file)` and `defaultWidth()` - rebind to serve pictures from another address or edit the width at runtime |

### Routes

```
GET  /help                              larafoundry.help.index      (reader.enabled)
GET  /help/pictures/{name}              larafoundry.help.picture    (always)
GET  /admin/help-articles               admin.help-articles.index   (admin.enabled; + data, sections.*, articles.*, pictures.*, translate)
```

## Security notes

- **Text is cleaned on write, once.** Every body goes through the `HtmlSanitizer` in `HelpEditor` (and in the guide loader); headings, titles and captions are stripped to plain text. Nothing downstream cleans again, so nothing else may write those columns. The reader renders bodies with `v-html` on that basis; pictures are built server-side with every value escaped.
- **The editor is super-admin only**, behind the admin zone gate (+ OTP), and every action re-checks identity through `HelpPolicy` (VisitorStatus).
- **Pictures are served without a session** - the help is read by guests. The name is a uuid the operator never chooses, and it is matched against the exact shape an upload writes **before** the disk is touched, so a path such as `..%2F` never reaches it. The type is read from the file's contents, not from the name the browser sent.
- **A translation is never saved on its own** and is refused when it lost a picture marker.

## Host integration

A host that draws the help on its own screens (its own layout, its own address) and edits it from a console of its own:

```php
// config/larafoundry-help.php
'reader' => ['enabled' => false, 'path' => 'help'],
'admin'  => ['enabled' => false],
```

then reads through `HelpBook::pages()` and writes through `HelpEditor`, validating with its rules. The pictures route stays on.

## Testing

`tests/Feature/Help/` covers the reader (published only, order, language fallback and its note, the phone text, fillers, picture markers and their attributes, the page and its switch), the editor (access, cleaning, blank texts, the kept phone text, ordering scoped to a section, cascading delete, translation present only with a translator, refusal of a lost picture), pictures (upload and free references, webp, a non-picture refused by its contents, serving to guests, the name check before the disk, rename and delete with the file) and the guide loader (positions, cleaning, update in place, prune and dry run, all-or-nothing, a doubled position refused). `tests-js/pages/Help.test.js` covers both pages.
