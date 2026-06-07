<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Email\Models\EmailTemplate;
use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Cache;

/*
| The mailMessage() seam (phase 5.1, sub-phase D): the single bridge the core's
| mail notifications use to render a template into a notification MailMessage,
| or null to signal a graceful fallback (D-5.1-8).
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    config()->set('larafoundry.locale.available', ['en']);
    config()->set('larafoundry.locale.default', 'en');
    config()->set('larafoundry-email.templates', [
        'welcome' => [
            'variables' => ['name', 'url'],
            'subject' => ['en' => 'Hi {{name}}'],
            'body_html' => ['en' => '<p>Hello {{name}} <a href="{{url}}">go</a></p>'],
            'body_text' => ['en' => 'Hello {{name}} {{url}}'],
        ],
    ]);

    $this->repo = app(EmailTemplateRepository::class);
});

it('builds a MailMessage from a template with the placeholders substituted', function () {
    $mail = $this->repo->mailMessage('welcome', 'en', ['name' => 'Jane', 'url' => 'https://x/go']);

    expect($mail)->toBeInstanceOf(MailMessage::class)
        ->and($mail->subject)->toBe('Hi Jane')
        ->and($mail->view)->toBe(['larafoundry::mail.html', 'larafoundry::mail.text'])
        ->and($mail->viewData['html'])->toContain('Hello Jane')->toContain('https://x/go')
        ->and($mail->viewData['text'])->toBe('Hello Jane https://x/go');
});

it('purifies the html part it hands to the mail view', function () {
    config()->set('larafoundry-email.templates.welcome.body_html.en', '<p>Hi {{name}}<script>alert(1)</script></p>');

    $mail = $this->repo->mailMessage('welcome', 'en', ['name' => 'Jane', 'url' => 'u']);

    expect($mail->viewData['html'])->toContain('Hi Jane')
        ->and($mail->viewData['html'])->not->toContain('<script>');
});

it('returns null for an unregistered code so the caller falls back', function () {
    expect($this->repo->mailMessage('nope', 'en', []))->toBeNull();
});

it('returns null when the template is switched off so the caller falls back', function () {
    EmailTemplate::create([
        'code' => 'welcome',
        'subject_translations' => [],
        'body_html_translations' => [],
        'body_text_translations' => [],
        'is_active' => false,
    ]);
    Cache::flush();

    expect($this->repo->mailMessage('welcome', 'en', ['name' => 'Jane', 'url' => 'u']))->toBeNull();
});
