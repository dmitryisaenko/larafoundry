<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Email\Models\EmailTemplate;
use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/*
 * The five phase-2a lifecycle email templates render from the shipped registry
 * (config/larafoundry-email.php) for en + uk, enforce their variable whitelist,
 * and fall back to null when switched off (the notification then uses its static
 * MailMessage).
 */

beforeEach(function () {
    Cache::flush();
    config()->set('larafoundry.locale.available', ['en', 'uk']);
    config()->set('larafoundry.locale.default', 'en');
    $this->repo = app(EmailTemplateRepository::class);
});

$codes = [
    'employee_joined_confirmation',
    'invitation_accepted_owner',
    'invitation_rejected_owner',
    'employee_removed_notification',
    'company_created',
    'company_deleted_confirmation',
];

it('renders the shipped template non-null for en and uk with sample data', function (string $code) {
    $data = $this->repo->sampleData($code);

    foreach (['en', 'uk'] as $locale) {
        $rendered = $this->repo->render($code, $locale, $data);

        expect($rendered)->not->toBeNull()
            ->and($rendered['subject'])->not->toBe('')
            ->and($rendered['html'])->not->toBe('')
            ->and($rendered['text'])->not->toBe('')
            // No unresolved placeholders leaked through.
            ->and($rendered['subject'])->not->toContain('{{')
            ->and($rendered['html'])->not->toContain('{{');
    }
})->with($codes);

it('declares a non-empty variable whitelist', function (string $code) {
    expect($this->repo->variablesFor($code))->not->toBeEmpty();
})->with($codes);

it('renders null (fallback path) when the template is switched off', function (string $code) {
    EmailTemplate::create([
        'code' => $code,
        'subject_translations' => [],
        'body_html_translations' => [],
        'body_text_translations' => [],
        'is_active' => false,
    ]);
    Cache::flush();

    expect($this->repo->render($code, 'en', $this->repo->sampleData($code)))->toBeNull();
})->with($codes);

it('carries the expected variables for each code', function () {
    expect($this->repo->variablesFor('employee_joined_confirmation'))
        ->toBe(['app_name', 'member_name', 'company_name']);
    expect($this->repo->variablesFor('invitation_accepted_owner'))
        ->toBe(['app_name', 'owner_name', 'member_name', 'company_name']);
    expect($this->repo->variablesFor('invitation_rejected_owner'))
        ->toBe(['app_name', 'owner_name', 'invited_email', 'company_name']);
    expect($this->repo->variablesFor('employee_removed_notification'))
        ->toBe(['app_name', 'member_name', 'company_name']);
    expect($this->repo->variablesFor('company_created'))
        ->toBe(['app_name', 'owner_name', 'company_name']);
    expect($this->repo->variablesFor('company_deleted_confirmation'))
        ->toBe(['app_name', 'owner_name', 'company_name']);
});
