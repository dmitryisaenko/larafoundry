<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Email\Models\EmailTemplate;
use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/*
 * Phase 2b: the marketing layer of the email-template repository. The registry
 * ('welcome') is the single transactional code; marketing rows are self-contained
 * DB entities. Fail-closed integrity of the transactional layer must survive.
 */

beforeEach(function () {
    Cache::flush();

    config()->set('larafoundry.locale.available', ['en', 'uk']);
    config()->set('larafoundry.locale.default', 'en');
    config()->set('larafoundry-email.templates', [
        'welcome' => [
            'variables' => ['name', 'app_name'],
            'subject' => ['en' => 'Welcome {{name}}', 'uk' => 'Вітаємо, {{name}}'],
            'body_html' => ['en' => '<p>Hi {{name}} from {{app_name}}</p>', 'uk' => '<p>Привіт, {{name}}</p>'],
            'body_text' => ['en' => 'Hi {{name}}', 'uk' => 'Привіт, {{name}}'],
        ],
    ]);

    $this->repo = app(EmailTemplateRepository::class);
});

/**
 * @return array<string, mixed>
 */
function marketingPayload(string $code = 'promo_launch'): array
{
    return [
        'code' => $code,
        'name' => 'Launch promo',
        'variables' => ['first_name', 'offer'],
        'is_active' => true,
        'subject' => ['en' => 'Hello {{first_name}}', 'uk' => 'Привіт, {{first_name}}'],
        'body_html' => ['en' => '<p>{{offer}} for {{first_name}}</p>', 'uk' => '<p>{{offer}}</p>'],
        'body_text' => ['en' => '{{offer}} for {{first_name}}', 'uk' => '{{offer}}'],
    ];
}

// --- Two layers -------------------------------------------------------------

it('lists both layers, each tagged with type and deletable flag', function () {
    $this->repo->createMarketing(marketingPayload());

    $all = collect($this->repo->all());

    $transactional = $all->firstWhere('code', 'welcome');
    $marketing = $all->firstWhere('code', 'promo_launch');

    expect($all)->toHaveCount(2)
        ->and($transactional['type'])->toBe('transactional')
        ->and($transactional['deletable'])->toBeFalse()
        ->and($marketing['type'])->toBe('marketing')
        ->and($marketing['name'])->toBe('Launch promo')
        ->and($marketing['deletable'])->toBeTrue();
});

// --- Create marketing (self-contained) --------------------------------------

it('creates a self-contained marketing row that renders without any registry entry', function () {
    $this->repo->createMarketing(marketingPayload());

    expect(EmailTemplate::where('code', 'promo_launch')->exists())->toBeTrue()
        ->and($this->repo->isRegistered('promo_launch'))->toBeFalse();

    $rendered = $this->repo->render('promo_launch', 'en', $this->repo->sampleData('promo_launch'));

    expect($rendered)->not->toBeNull()
        // Sample data for the row's OWN variables (labelled placeholders, since
        // 'offer' / 'first_name' are not known infrastructure variables).
        ->and($rendered['html'])->toContain('[offer] for [first_name]');

    expect($this->repo->mailMessage('promo_launch', 'en', $this->repo->sampleData('promo_launch')))->not->toBeNull();
});

it('reads a marketing template own variable whitelist', function () {
    $this->repo->createMarketing(marketingPayload());

    expect($this->repo->variablesFor('promo_launch'))->toBe(['first_name', 'offer']);
});

// --- Fail-closed against the transactional layer ----------------------------

it('refuses to create a marketing template colliding with a registry code', function () {
    expect(fn () => $this->repo->createMarketing(marketingPayload('welcome')))
        ->toThrow(RuntimeException::class);
});

it('refuses to create a marketing template colliding with an existing marketing row', function () {
    $this->repo->createMarketing(marketingPayload());

    expect(fn () => $this->repo->createMarketing(marketingPayload()))
        ->toThrow(RuntimeException::class);
});

it('is fail-closed: saving a truly unregistered non-marketing code still throws', function () {
    expect(fn () => $this->repo->save('nope', ['subject' => [], 'body_html' => [], 'body_text' => []]))
        ->toThrow(RuntimeException::class);
});

it('refuses to delete a transactional/registry code', function () {
    // Even with an override row present, a registry code can never be deleted.
    $this->repo->save('welcome', [
        'is_active' => true,
        'subject' => ['en' => 'S', 'uk' => 'S'],
        'body_html' => ['en' => '<p>x</p>', 'uk' => '<p>x</p>'],
        'body_text' => ['en' => 'x', 'uk' => 'x'],
    ]);

    expect(fn () => $this->repo->deleteMarketing('welcome'))->toThrow(RuntimeException::class);
    expect(EmailTemplate::where('code', 'welcome')->exists())->toBeTrue();
});

// --- Duplicate --------------------------------------------------------------

it('duplicates a transactional code into a NEW marketing row, leaving the original untouched', function () {
    $copy = $this->repo->duplicate('welcome');

    expect($copy->type)->toBe('marketing')
        ->and($copy->code)->not->toBe('welcome')
        ->and($copy->code)->toBe('welcome_copy');

    // The registry code is untouched: no transactional DB row was created for it,
    // and it still renders through its registry default (transactional sender).
    expect(EmailTemplate::where('code', 'welcome')->exists())->toBeFalse()
        ->and($this->repo->find('welcome')['type'])->toBe('transactional');

    // The copy carries its own whitelist and renders as a self-contained marketing row.
    expect($copy->variables)->toBe(['name', 'app_name'])
        ->and($this->repo->render('welcome_copy', 'en', $this->repo->sampleData('welcome_copy')))->not->toBeNull();
});

it('duplicates a marketing row into another marketing row', function () {
    $this->repo->createMarketing(marketingPayload());

    $copy = $this->repo->duplicate('promo_launch');

    expect($copy->type)->toBe('marketing')
        ->and($copy->code)->toBe('promo_launch_copy')
        ->and($copy->variables)->toBe(['first_name', 'offer']);
});

it('clamps a near-255-char source code so the duplicate stays within the column', function () {
    // A marketing source whose code is at the 255-char column limit.
    $longCode = str_repeat('a', 255);
    $this->repo->createMarketing(marketingPayload($longCode));

    $copy = $this->repo->duplicate($longCode);

    expect(mb_strlen($copy->code))->toBeLessThanOrEqual(255)
        ->and($copy->code)->toEndWith('_copy')
        ->and(EmailTemplate::where('code', $copy->code)->exists())->toBeTrue();
});

// --- Edit + delete marketing ------------------------------------------------

it('edits a marketing row name, variables, subject and body', function () {
    $this->repo->createMarketing(marketingPayload());

    $this->repo->save('promo_launch', [
        'is_active' => true,
        'name' => 'Renamed promo',
        'variables' => ['first_name', 'offer', 'coupon'],
        'subject' => ['en' => 'Hi {{first_name}} {{coupon}}', 'uk' => 'Привіт'],
        'body_html' => ['en' => '<p>{{coupon}}</p>', 'uk' => '<p>x</p>'],
        'body_text' => ['en' => '{{coupon}}', 'uk' => 'x'],
    ]);

    $row = EmailTemplate::where('code', 'promo_launch')->first();

    expect($row->type)->toBe('marketing')
        ->and($row->name)->toBe('Renamed promo')
        ->and($row->variables)->toBe(['first_name', 'offer', 'coupon'])
        ->and($row->subject_translations['en'])->toBe('Hi {{first_name}} {{coupon}}');
});

it('deletes a marketing row so render then returns null', function () {
    $this->repo->createMarketing(marketingPayload());

    $this->repo->deleteMarketing('promo_launch');

    expect(EmailTemplate::where('code', 'promo_launch')->exists())->toBeFalse()
        ->and($this->repo->render('promo_launch', 'en', []))->toBeNull();
});

// --- XSS --------------------------------------------------------------------

it('purifies a script out of a marketing body on write AND on render', function () {
    $payload = marketingPayload();
    $payload['body_html']['en'] = '<p>{{offer}}</p><script>alert(1)</script>';

    $this->repo->createMarketing($payload);

    $stored = EmailTemplate::where('code', 'promo_launch')->first()->body_html_translations['en'];
    expect($stored)->not->toContain('<script');

    $rendered = $this->repo->render('promo_launch', 'en', $this->repo->sampleData('promo_launch'));
    expect($rendered['html'])->not->toContain('<script');
});
