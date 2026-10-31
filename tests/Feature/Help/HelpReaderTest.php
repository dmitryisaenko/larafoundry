<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Help\Contracts\HelpTextFiller;
use Dmitryisaenko\LaraFoundry\Help\Models\HelpImage;
use Dmitryisaenko\LaraFoundry\Help\Models\HelpSection;
use Dmitryisaenko\LaraFoundry\Help\Support\HelpBook;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('larafoundry.locale.available', ['en', 'uk']);
    config()->set('larafoundry.locale.default', 'en');
    config()->set('larafoundry-help.source_locale', 'uk');
});

function helpSection(string $key, array $title, int $position = 10, bool $published = true): HelpSection
{
    return HelpSection::query()->create([
        'key' => $key,
        'title_translations' => $title,
        'blurb_translations' => [],
        'position' => $position,
        'is_published' => $published,
    ]);
}

function helpArticle(HelpSection $section, array $heading, array $body, ?array $mobile = null, int $position = 10, bool $published = true): void
{
    $section->articles()->create([
        'heading_translations' => $heading,
        'body_translations' => $body,
        'mobile_body_translations' => $mobile,
        'position' => $position,
        'is_published' => $published,
    ]);
}

it('reads the published sections and articles in order', function () {
    $second = helpSection('billing', ['en' => 'Billing'], 20);
    $first = helpSection('start', ['en' => 'Start'], 10);
    helpSection('hidden', ['en' => 'Hidden'], 30, published: false);

    helpArticle($first, ['en' => 'B'], ['en' => '<p>b</p>'], position: 20);
    helpArticle($first, ['en' => 'A'], ['en' => '<p>a</p>'], position: 10);
    helpArticle($first, ['en' => 'Draft'], ['en' => '<p>x</p>'], position: 30, published: false);
    helpArticle($second, ['en' => 'Pay'], ['en' => '<p>pay</p>']);

    $pages = app(HelpBook::class)->pages('en');

    expect(array_keys($pages))->toBe(['start', 'billing'])
        ->and(array_column($pages['start']['items'], 'heading'))->toBe(['A', 'B']);
});

it('falls back to the source language and says so', function () {
    $section = helpSection('start', ['uk' => 'Початок']);
    helpArticle($section, ['uk' => 'Заголовок'], ['uk' => '<p>Текст</p>']);

    $item = app(HelpBook::class)->pages('en')['start']['items'][0];

    expect($item['heading'])->toBe('Заголовок')
        ->and($item['body'])->toBe('<p>Текст</p>')
        ->and($item['body_locale'])->toBe('uk');
});

it('says nothing about the language when the reader gets their own', function () {
    $section = helpSection('start', ['en' => 'Start', 'uk' => 'Початок']);
    helpArticle($section, ['en' => 'H'], ['en' => '<p>en</p>', 'uk' => '<p>uk</p>']);

    $item = app(HelpBook::class)->pages('en')['start']['items'][0];

    expect($item['body'])->toBe('<p>en</p>')
        ->and($item['body_locale'])->toBeNull();
});

it('carries the phone text only where one is written', function () {
    $section = helpSection('start', ['en' => 'Start']);
    helpArticle($section, ['en' => 'With'], ['en' => '<p>desk</p>'], mobile: ['en' => '<p>phone</p>'], position: 10);
    helpArticle($section, ['en' => 'Blank'], ['en' => '<p>desk</p>'], mobile: ['en' => '<p></p>'], position: 20);

    $items = app(HelpBook::class)->pages('en')['start']['items'];

    expect($items[0]['body_mobile'])->toBe('<p>phone</p>')
        ->and($items[1]['body_mobile'])->toBeNull();
});

it('lets a phone text in a substitute language give way to the reader\'s own text', function () {
    config()->set('larafoundry-help.source_locale', 'en');

    $section = helpSection('start', ['en' => 'Start', 'uk' => 'Старт']);
    // The phone text written in the source only, the desktop one translated.
    helpArticle($section, ['en' => 'A'], ['en' => '<p>desk</p>', 'uk' => '<p>стіл</p>'], mobile: ['en' => '<p>phone</p>'], position: 10);
    // Nothing in the reader's language: the substitute phone text still beats the substitute desktop one.
    helpArticle($section, ['en' => 'B'], ['en' => '<p>desk</p>'], mobile: ['en' => '<p>phone</p>'], position: 20);

    $items = app(HelpBook::class)->pages('uk')['start']['items'];

    expect($items[0]['body_mobile'])->toBeNull()
        ->and($items[0]['body_locale'])->toBeNull()
        ->and($items[1]['body_mobile'])->toBe('<p>phone</p>')
        ->and($items[1]['body_mobile_locale'])->toBe('en');
});

it('runs every registered filler over every text', function () {
    $section = helpSection('start', ['en' => 'Costs :price']);
    helpArticle($section, ['en' => 'H :price'], ['en' => '<p>:price each</p>'], mobile: ['en' => '<p>:price</p>']);

    app(HelpBook::class)->addFiller(new class implements HelpTextFiller
    {
        public function fill(string $text, string $locale): string
        {
            return str_replace(':price', '42', $text);
        }
    });

    $page = app(HelpBook::class)->pages('en')['start'];

    expect($page['title'])->toBe('Costs 42')
        ->and($page['items'][0]['heading'])->toBe('H 42')
        ->and($page['items'][0]['body'])->toBe('<p>42 each</p>')
        ->and($page['items'][0]['body_mobile'])->toBe('<p>42</p>');
});

it('makes a fresh filler from a factory on every reading', function () {
    helpArticle(helpSection('start', ['en' => 'Start']), ['en' => 'H'], ['en' => '<p>:n</p>']);

    $made = 0;
    $book = app(HelpBook::class);

    // A filler that looks its value up once, as a host's price filler does.
    $book->addFiller(function () use (&$made): HelpTextFiller {
        $made++;

        return new class($made) implements HelpTextFiller
        {
            public function __construct(private readonly int $n) {}

            public function fill(string $text, string $locale): string
            {
                return str_replace(':n', (string) $this->n, $text);
            }
        };
    });

    expect($book->pages('en')['start']['items'][0]['body'])->toBe('<p>1</p>')
        ->and($book->pages('en')['start']['items'][0]['body'])->toBe('<p>2</p>')
        ->and($made)->toBe(2);
});

it('resolves picture markers when the help is read', function () {
    HelpImage::query()->create(['ref' => 'shot', 'file' => 'f.webp', 'caption_translations' => ['en' => 'A shot'], 'max_width' => 400]);

    $section = helpSection('start', ['en' => 'Start']);
    helpArticle($section, ['en' => 'H'], ['en' => '<p>[[img:shot]]</p><p>[[img:SHOT|width=full|align=left]]</p><p>[[img:nope]]</p>']);

    $body = app(HelpBook::class)->pages('en')['start']['items'][0]['body'];

    expect($body)->toContain('<figure class="help-pic" style="max-width:400px"><img src="'.route('larafoundry.help.picture', ['name' => 'f.webp']).'" alt="A shot"><figcaption>A shot</figcaption></figure>')
        ->and($body)->toContain('<figure class="help-pic is-left"><img')
        ->and($body)->not->toContain('[[img:')
        // A paragraph holding only the marker goes with it: no figure inside a `<p>`.
        ->and($body)->not->toContain('<p><figure')
        ->and($body)->not->toContain('<p></p>');
});

it('leaves a marker inside an attribute alone', function () {
    HelpImage::query()->create(['ref' => 'shot', 'file' => 'f.webp', 'caption_translations' => []]);

    $section = helpSection('start', ['en' => 'Start']);
    helpArticle($section, ['en' => 'H'], ['en' => '<p><a href="https://x.test" title="[[img:shot]]">link</a> [[img:shot]]</p>']);

    $body = app(HelpBook::class)->pages('en')['start']['items'][0]['body'];

    expect($body)->toContain('title="[[img:shot]]">link</a> <figure class="help-pic"');
});

it('lets the text set the width, drops nonsense, and falls back to the default', function () {
    config()->set('larafoundry-help.pictures.default_width', 650);
    HelpImage::query()->create(['ref' => 'shot', 'file' => 'f.webp', 'caption_translations' => []]);

    $section = helpSection('start', ['en' => 'Start']);
    helpArticle($section, ['en' => 'H'], ['en' => '<p>[[img:shot|width=small]]</p><p>[[img:shot|width=4000|align=centre]]</p>']);

    $body = app(HelpBook::class)->pages('en')['start']['items'][0]['body'];

    expect($body)->toContain('style="max-width:300px"')
        ->and($body)->toContain('<figure class="help-pic" style="max-width:650px">');
});

it('shows the page to a guest and opens the asked section', function () {
    helpArticle(helpSection('start', ['en' => 'Start'], 10), ['en' => 'H'], ['en' => '<p>a</p>']);
    helpArticle(helpSection('billing', ['en' => 'Billing'], 20), ['en' => 'H'], ['en' => '<p>b</p>']);

    $this->withHeader('X-Inertia', 'true')
        ->get('/help?section=billing')
        ->assertOk()
        ->assertJsonPath('component', 'Help/Index')
        ->assertJsonPath('props.open', 'billing');

    $this->withHeader('X-Inertia', 'true')
        ->get('/help?section=nope')
        ->assertJsonPath('props.open', 'start');
});

it('answers 404 when nothing is published', function () {
    helpSection('hidden', ['en' => 'Hidden'], published: false);

    $this->get('/help')->assertNotFound();
});

it('does not register the page while the reader is switched off', function () {
    config()->set('larafoundry-help.reader.enabled', false);

    $router = new Router(app('events'), app());
    Route::swap($router);

    require __DIR__.'/../../../routes/help.php';
    $router->getRoutes()->refreshNameLookups();

    expect($router->getRoutes()->getByName('larafoundry.help.index'))->toBeNull()
        ->and($router->getRoutes()->getByName('larafoundry.help.picture'))->not->toBeNull();
});
