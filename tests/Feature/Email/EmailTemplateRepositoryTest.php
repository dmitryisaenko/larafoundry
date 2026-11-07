<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Email\Models\EmailTemplate;
use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

beforeEach(function () {
    // One override row caches under a single key; flush so each test starts clean.
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

it('resolves the registry default when there is no override', function () {
    $template = $this->repo->find('welcome');

    expect($template['subject']['en'])->toBe('Welcome {{name}}')
        ->and($template['is_active'])->toBeTrue()
        ->and($template['variables'])->toBe(['name', 'app_name']);
});

it('returns null for an unregistered code', function () {
    expect($this->repo->find('nope'))->toBeNull();
});

it('merges a stored override over the registry default per locale', function () {
    EmailTemplate::create([
        'code' => 'welcome',
        'subject_translations' => ['en' => 'Hey {{name}}'], // override only EN subject
        'body_html_translations' => ['en' => '<p>Custom {{name}}</p>'],
        'body_text_translations' => ['en' => 'Custom {{name}}'],
        'is_active' => true,
    ]);

    $template = $this->repo->find('welcome');

    // EN comes from the override, UK falls back to the registry default.
    expect($template['subject']['en'])->toBe('Hey {{name}}')
        ->and($template['subject']['uk'])->toBe('Вітаємо, {{name}}');
});

it('is fail-closed: saving an unregistered code throws', function () {
    expect(fn () => $this->repo->save('nope', ['subject' => [], 'body_html' => [], 'body_text' => []]))
        ->toThrow(RuntimeException::class);
});

it('purifies the html body on save', function () {
    $this->repo->save('welcome', [
        'is_active' => true,
        'subject' => ['en' => 'S', 'uk' => 'S'],
        'body_html' => ['en' => '<p>ok</p><script>alert(1)</script>', 'uk' => '<p>ok</p>'],
        'body_text' => ['en' => 'ok', 'uk' => 'ok'],
    ]);

    $row = EmailTemplate::where('code', 'welcome')->first();

    expect($row->body_html_translations['en'])
        ->toContain('<p>ok</p>')
        ->not->toContain('<script');
});

it('busts the cache on save so the next read sees the override', function () {
    // Prime the cache with the default.
    expect($this->repo->find('welcome')['subject']['en'])->toBe('Welcome {{name}}');

    $this->repo->save('welcome', [
        'is_active' => true,
        'subject' => ['en' => 'Changed', 'uk' => 'Changed'],
        'body_html' => ['en' => '<p>x</p>', 'uk' => '<p>x</p>'],
        'body_text' => ['en' => 'x', 'uk' => 'x'],
    ]);

    expect($this->repo->find('welcome')['subject']['en'])->toBe('Changed');
});

it('renders a template for delivery with substituted data', function () {
    $rendered = $this->repo->render('welcome', 'en', ['name' => 'Jane', 'app_name' => 'Acme']);

    expect($rendered['subject'])->toBe('Welcome Jane')
        ->and($rendered['html'])->toContain('Hi Jane from Acme')
        ->and($rendered['text'])->toBe('Hi Jane');
});

it('falls back to the default locale when the requested one is missing', function () {
    config()->set('larafoundry-email.templates.welcome.subject', ['en' => 'Welcome {{name}}']); // only EN

    $rendered = $this->repo->render('welcome', 'uk', ['name' => 'Jane']);

    expect($rendered['subject'])->toBe('Welcome Jane');
});

it('takes a language the registry lacks from the host lang file', function () {
    config()->set('larafoundry.locale.available', ['en', 'uk', 'de']);
    app('translator')->addLines([
        'email_templates.welcome.subject' => 'Willkommen, {{name}}',
        'email_templates.welcome.body_html' => '<p>Hallo {{name}} von {{app_name}}</p>',
        'email_templates.welcome.body_text' => 'Hallo {{name}}',
    ], 'de', 'larafoundry');

    $rendered = $this->repo->render('welcome', 'de', ['name' => 'Jane', 'app_name' => 'Acme']);

    expect($rendered['subject'])->toBe('Willkommen, Jane')
        ->and($rendered['html'])->toContain('Hallo Jane von Acme')
        ->and($rendered['text'])->toBe('Hallo Jane')
        ->and($this->repo->find('welcome')['subject'])->toHaveKeys(['en', 'uk', 'de']);
});

it('lets the host text replace the registry text of the same language, under a stored override', function () {
    app('translator')->addLines(['email_templates.welcome.subject' => 'Добрий день, {{name}}'], 'uk', 'larafoundry');

    expect($this->repo->render('welcome', 'uk', ['name' => 'Jane'])['subject'])->toBe('Добрий день, Jane')
        // Only the subject came from the host: the registry body stays.
        ->and($this->repo->render('welcome', 'uk', ['name' => 'Jane'])['text'])->toBe('Привіт, Jane');

    EmailTemplate::create([
        'code' => 'welcome',
        'subject_translations' => ['uk' => 'Від адміністратора, {{name}}'],
        'body_html_translations' => [],
        'body_text_translations' => [],
        'is_active' => true,
    ]);
    Cache::flush();

    expect($this->repo->render('welcome', 'uk', ['name' => 'Jane'])['subject'])->toBe('Від адміністратора, Jane');
});

it('does not hand one language the host text of another', function () {
    config()->set('larafoundry.locale.available', ['en', 'uk', 'de', 'fr']);
    app('translator')->addLines(['email_templates.welcome.subject' => 'Willkommen, {{name}}'], 'de', 'larafoundry');

    // French has no file: it falls back to the default locale (en), never to German.
    expect($this->repo->render('welcome', 'fr', ['name' => 'Jane'])['subject'])->toBe('Welcome Jane')
        ->and($this->repo->find('welcome')['subject'])->not->toHaveKey('fr');
});

it('ignores a host language that the installation does not offer', function () {
    app('translator')->addLines(['email_templates.welcome.subject' => 'Willkommen, {{name}}'], 'de', 'larafoundry');

    expect($this->repo->find('welcome')['subject'])->not->toHaveKey('de');
});

it('returns null from render when the template is switched off', function () {
    EmailTemplate::create([
        'code' => 'welcome',
        'subject_translations' => ['en' => 'S'],
        'body_html_translations' => ['en' => '<p>x</p>'],
        'body_text_translations' => ['en' => 'x'],
        'is_active' => false,
    ]);

    expect($this->repo->render('welcome', 'en', ['name' => 'Jane']))->toBeNull();
});

it('returns null from render for an unregistered code', function () {
    expect($this->repo->render('nope', 'en', []))->toBeNull();
});

it('lists every registered template with its customised flag', function () {
    $all = $this->repo->all();

    expect($all)->toHaveCount(1)
        ->and($all[0]['code'])->toBe('welcome')
        ->and($all[0]['customized'])->toBeFalse();
});
