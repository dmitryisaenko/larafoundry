<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Help\Models\HelpImage;
use Dmitryisaenko\LaraFoundry\Help\Models\HelpSection;
use Dmitryisaenko\LaraFoundry\Help\Support\HelpBook;
use Dmitryisaenko\LaraFoundry\Help\Support\HelpLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('larafoundry.locale.available', ['en']);
    config()->set('larafoundry.locale.default', 'en');
    config()->set('larafoundry-help.source_locale', 'en');
});

function linksSection(): HelpSection
{
    return HelpSection::query()->create([
        'key' => 'study',
        'title_translations' => ['en' => 'Study'],
        'blurb_translations' => [],
        'position' => 10,
        'is_published' => true,
    ]);
}

function linksArticle(HelpSection $section, int $position, string $heading, string $body, ?string $mobile = null, bool $published = true): void
{
    $section->articles()->create([
        'heading_translations' => ['en' => $heading],
        'body_translations' => ['en' => $body],
        'mobile_body_translations' => $mobile === null ? null : ['en' => $mobile],
        'position' => $position,
        'is_published' => $published,
    ]);
}

/** @return list<array<string, mixed>> */
function linkedItems(): array
{
    return app(HelpBook::class)->pages('en')['study']['items'];
}

function linkTo(int $article, string $text): string
{
    return '<a href="?section=study&amp;article='.$article.'" class="help-link" data-help-section="study" data-help-article="'.$article.'">'.$text.'</a>';
}

it('turns another article\'s heading into a link to it, counted among the published', function () {
    $section = linksSection();
    linksArticle($section, 10, 'Getting started', '<p>Read What costs money first.</p>');
    linksArticle($section, 20, 'A draft', '<p>x</p>', published: false);
    linksArticle($section, 30, 'What costs money', '<p>Getting started is free.</p>');

    $items = linkedItems();

    // The draft is not counted: the second published article is number 2.
    expect($items[0]['body'])->toBe('<p>Read '.linkTo(2, 'What costs money').' first.</p>')
        ->and($items[1]['body'])->toBe('<p>'.linkTo(1, 'Getting started').' is free.</p>');
});

it('never links an article to itself', function () {
    $section = linksSection();
    linksArticle($section, 10, 'Getting started', '<p>Getting started is easy.</p>');
    linksArticle($section, 20, 'Other', '<p>-</p>');

    expect(linkedItems()[0]['body'])->toBe('<p>Getting started is easy.</p>');
});

it('links a heading once per paragraph or list item', function () {
    $section = linksSection();
    linksArticle($section, 10, 'Prices', '<p>-</p>');
    linksArticle($section, 20, 'Notes', '<p>Prices, and Prices again.</p><ul><li>See Prices.</li></ul>');

    expect(linkedItems()[1]['body'])->toBe(
        '<p>'.linkTo(1, 'Prices').', and Prices again.</p><ul><li>See '.linkTo(1, 'Prices').'.</li></ul>'
    );
});

it('learns terms from a glossary and links each one\'s first mention elsewhere', function () {
    $section = linksSection();
    linksArticle($section, 10, 'The word menu', '<p>The word list opens it. Then the word list again.</p>');
    linksArticle($section, 20, 'Glossary', '<ul><li><strong>Word list</strong> - all your words. More: The word menu.</li></ul>');

    $items = linkedItems();

    // The glossary's own line leads to the article; the glossary gets no term links.
    expect($items[1]['body'])->toBe('<ul><li><strong>Word list</strong> - all your words. More: '.linkTo(1, 'The word menu').'.</li></ul>')
        // The term mid-sentence, first letter lowered, linked once - not in the article it points to.
        ->and($items[0]['body'])->toBe('<p>The word list opens it. Then the word list again.</p>');

    linksArticle($section, 30, 'Third', '<p>The word list opens it. Then the word list again.</p>');

    expect(linkedItems()[2]['body'])->toBe('<p>The '.linkTo(1, 'word list').' opens it. Then the word list again.</p>');
});

it('takes a list item for a glossary line only when the heading follows a colon', function () {
    $section = linksSection();
    linksArticle($section, 10, 'Prices', '<p>-</p>');
    linksArticle($section, 20, 'Notes', '<ul><li><strong>Word list</strong> is explained in Prices.</li></ul>');
    linksArticle($section, 30, 'Third', '<p>The word list.</p>');

    $items = linkedItems();

    // Not a glossary: no term was learned, and the heading in it is still a link.
    expect($items[2]['body'])->toBe('<p>The word list.</p>')
        ->and($items[1]['body'])->toContain(linkTo(1, 'Prices'));
});

it('links a one-word term only as a name: in quotes, or an abbreviation', function () {
    $section = linksSection();
    linksArticle($section, 10, 'Levels and more', '<p>-</p>');
    linksArticle($section, 20, 'The SOS button', '<p>-</p>');
    linksArticle($section, 30, 'Glossary', '<ul>'
        .'<li><strong>Level</strong> - how hard. More: Levels and more.</li>'
        .'<li><strong>SOS</strong> - help. More: The SOS button.</li>'
        .'</ul>');
    linksArticle($section, 40, 'A game', '<p>Level one lasts a minute. Press SOS. Open «Level».</p>');

    expect(linkedItems()[3]['body'])->toBe(
        '<p>Level one lasts a minute. Press '.linkTo(2, 'SOS').'. Open «'.linkTo(1, 'Level').'».</p>'
    );
});

it('leaves headings, links and pictures alone', function () {
    HelpImage::query()->create(['ref' => 'shot', 'file' => 'shot.png', 'caption_translations' => ['en' => 'Prices']]);

    $section = linksSection();
    linksArticle($section, 10, 'Prices', '<p>-</p>');
    linksArticle($section, 20, 'Notes', '<h4>Prices</h4><p><a href="/x">Prices</a></p>[[img:shot]]');

    $body = linkedItems()[1]['body'];

    expect($body)->toStartWith('<h4>Prices</h4><p><a href="/x">Prices</a></p><figure')
        ->and($body)->toContain('<figcaption>Prices</figcaption>')
        ->and($body)->not->toContain('help-link');
});

it('links the phone text too', function () {
    $section = linksSection();
    linksArticle($section, 10, 'Prices', '<p>-</p>');
    linksArticle($section, 20, 'Notes', '<p>Prices</p>', '<p>Tap Prices.</p>');

    expect(linkedItems()[1]['body_mobile'])->toBe('<p>Tap '.linkTo(1, 'Prices').'.</p>');
});

it('lays no links when switched off', function () {
    config()->set('larafoundry-help.links', false);

    $section = linksSection();
    linksArticle($section, 10, 'Prices', '<p>-</p>');
    linksArticle($section, 20, 'Notes', '<p>See Prices.</p>');

    expect(linkedItems()[1]['body'])->toBe('<p>See Prices.</p>');
});

it('keeps a paragraph whose bytes are not UTF-8 rather than emptying it', function () {
    $section = linksSection();
    linksArticle($section, 10, 'Prices', '<p>-</p>');

    $body = app(HelpLinks::class);
    $method = new ReflectionMethod($body, 'inBody');

    $out = $method->invoke($body, "<p>Prices \xC3\x28 here</p>", 'study', 2, '/Prices/u', ['Prices' => 1], []);

    expect($out)->toBe("<p>Prices \xC3\x28 here</p>");
});
