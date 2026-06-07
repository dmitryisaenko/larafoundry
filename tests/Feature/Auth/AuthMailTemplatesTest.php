<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Email\Models\EmailTemplate;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/*
| The Fortify/Laravel auth mails (verify-email, password-reset) routed through
| the editable templates by localizeAuthMail(), with a localised static fallback
| (phase 5.1, sub-phase D). Exercises the closures directly — including the
| password-reset URL building — not just the mailMessage() seam.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

function amtUser(): User
{
    return User::create([
        'name' => 'Jane',
        'email' => 'jane@x.test',
        'password' => 'secret-pass',
        'locale' => 'en',
        'email_verified_at' => now(),
    ]);
}

it('renders the password-reset mail from the template, carrying the reset link', function () {
    $mail = (new ResetPassword('tok-123'))->toMail(amtUser());

    expect($mail->view)->toBe(['larafoundry::mail.html', 'larafoundry::mail.text'])
        ->and($mail->viewData['html'])->toContain('tok-123');
});

it('falls back to the static password-reset mail when the template is switched off', function () {
    EmailTemplate::create([
        'code' => 'password_reset',
        'subject_translations' => [],
        'body_html_translations' => [],
        'body_text_translations' => [],
        'is_active' => false,
    ]);
    Cache::flush();

    $mail = (new ResetPassword('tok-123'))->toMail(amtUser());

    expect($mail->view)->toBeNull()
        ->and($mail->subject)->toBe(__('larafoundry::auth.reset_password.subject'))
        ->and($mail->actionUrl)->toContain('tok-123');
});

it('renders the email-verification mail from the template', function () {
    // The default VerifyEmail builds a signed verification.verify URL; stub the
    // route so the closure receives a real URL to inject as {{verification_url}}.
    Route::get('/verify/{id}/{hash}', fn () => '')->name('verification.verify');

    $user = amtUser();
    $mail = (new VerifyEmail)->toMail($user);

    expect($mail->view)->toBe(['larafoundry::mail.html', 'larafoundry::mail.text'])
        ->and($mail->viewData['html'])->toContain('Jane');
});

it('falls back to the static email-verification mail when the template is switched off', function () {
    Route::get('/verify/{id}/{hash}', fn () => '')->name('verification.verify');

    EmailTemplate::create([
        'code' => 'email_verification',
        'subject_translations' => [],
        'body_html_translations' => [],
        'body_text_translations' => [],
        'is_active' => false,
    ]);
    Cache::flush();

    $mail = (new VerifyEmail)->toMail(amtUser());

    expect($mail->view)->toBeNull()
        ->and($mail->subject)->toBe(__('larafoundry::auth.verify_email.subject'));
});
