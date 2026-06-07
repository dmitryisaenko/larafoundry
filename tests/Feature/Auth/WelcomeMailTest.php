<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Auth\Notifications\WelcomeNotification;
use Dmitryisaenko\LaraFoundry\Email\Models\EmailTemplate;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;

/*
| Welcome email (phase 5.1, decision D-5.1-13): a WelcomeNotification fired on
| the Verified event, rendering from the editable `welcome_email` template with a
| graceful static fallback.
*/

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
});

function welcomeUser(string $locale = 'en'): User
{
    return User::create([
        'name' => 'Jane',
        'email' => 'jane@x.test',
        'password' => 'secret-pass',
        'locale' => $locale,
        'email_verified_at' => now(),
    ]);
}

it('renders the welcome mail from the welcome_email template', function () {
    $mail = (new WelcomeNotification)->toMail(welcomeUser());

    expect($mail->view)->toBe(['larafoundry::mail.html', 'larafoundry::mail.text'])
        ->and($mail->viewData['html'])->toContain('Jane');
});

it('falls back to the localised static mail when the template is switched off', function () {
    EmailTemplate::create([
        'code' => 'welcome_email',
        'subject_translations' => [],
        'body_html_translations' => [],
        'body_text_translations' => [],
        'is_active' => false,
    ]);
    Cache::flush();

    $mail = (new WelcomeNotification)->toMail(welcomeUser());

    expect($mail->view)->toBeNull()
        ->and($mail->subject)->toBe(__('larafoundry::auth.welcome.subject', ['app' => config('app.name')]))
        ->and($mail->actionUrl)->toBe(url('/login'));
});

it('sends the welcome notification when a user verifies their email', function () {
    Notification::fake();

    $user = welcomeUser();

    event(new Verified($user));

    Notification::assertSentTo($user, WelcomeNotification::class);
});
