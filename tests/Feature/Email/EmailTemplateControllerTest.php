<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Email\Models\EmailTemplate;
use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    // Operator console without the OTP step-up, the flag-only super-admin model.
    config()->set('larafoundry.security.super_admin.require_otp', false);
    config()->set('larafoundry.security.super_admin.email', null);

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
});

function emailUser(string $email = 'u@x.test', bool $admin = false): User
{
    return User::create([
        'name' => 'U',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
        'is_admin' => $admin,
    ]);
}

/**
 * @return array<string, mixed>
 */
function validTemplatePayload(): array
{
    return [
        'is_active' => true,
        'subject' => ['en' => 'Hello {{name}}', 'uk' => 'Привіт, {{name}}'],
        'body_html' => ['en' => '<p>Hi {{name}}</p>', 'uk' => '<p>Привіт, {{name}}</p>'],
        'body_text' => ['en' => 'Hi {{name}}', 'uk' => 'Привіт, {{name}}'],
    ];
}

// --- Page rendering ---------------------------------------------------------

it('renders the templates list for a super-admin', function () {
    $this->actingAs(emailUser('boss@x.test', admin: true))
        ->withHeader('X-Inertia', 'true')
        ->get('/admin/email-templates')
        ->assertOk()
        ->assertJsonPath('component', 'Admin/EmailTemplates/Index');
});

it('forbids a non-admin from the templates list', function () {
    $this->actingAs(emailUser())
        ->get('/admin/email-templates')
        ->assertForbidden();
});

it('renders the editor for a registered template', function () {
    $this->actingAs(emailUser('boss@x.test', admin: true))
        ->withHeader('X-Inertia', 'true')
        ->get('/admin/email-templates/welcome/edit')
        ->assertOk()
        ->assertJsonPath('component', 'Admin/EmailTemplates/Edit')
        ->assertJsonPath('props.template.code', 'welcome');
});

it('404s the editor for an unregistered template', function () {
    $this->actingAs(emailUser('boss@x.test', admin: true))
        ->get('/admin/email-templates/nope/edit')
        ->assertNotFound();
});

// --- Update -----------------------------------------------------------------

it('lets a super-admin save a template', function () {
    $this->actingAs(emailUser('boss@x.test', admin: true))
        ->put('/admin/email-templates/welcome', validTemplatePayload())
        ->assertRedirect();

    expect(EmailTemplate::where('code', 'welcome')->exists())->toBeTrue();
});

it('STRICTLY rejects a variable outside the template whitelist', function () {
    $payload = validTemplatePayload();
    $payload['body_html']['en'] = '<p>Hi {{name}} {{secret_token}}</p>';

    $this->actingAs(emailUser('boss@x.test', admin: true))
        ->put('/admin/email-templates/welcome', $payload)
        ->assertSessionHasErrors('body_html.en');

    expect(EmailTemplate::where('code', 'welcome')->exists())->toBeFalse();
});

it('purifies a script out of the html body on save', function () {
    $payload = validTemplatePayload();
    $payload['body_html']['en'] = '<p>Hi {{name}}</p><script>alert(1)</script>';

    $this->actingAs(emailUser('boss@x.test', admin: true))
        ->put('/admin/email-templates/welcome', $payload)
        ->assertRedirect();

    expect(EmailTemplate::where('code', 'welcome')->first()->body_html_translations['en'])
        ->not->toContain('<script');
});

it('forbids a non-admin from saving a template', function () {
    $this->actingAs(emailUser())
        ->put('/admin/email-templates/welcome', validTemplatePayload())
        ->assertForbidden();
});

// --- Preview ----------------------------------------------------------------

it('returns a server-rendered, purified preview', function () {
    $this->actingAs(emailUser('boss@x.test', admin: true))
        ->postJson('/admin/email-templates/welcome/preview', [
            'locale' => 'en',
            'subject' => 'Hi {{name}}',
            'body_html' => '<p>Hi {{name}}</p><script>alert(1)</script>',
            'body_text' => 'Hi {{name}}',
        ])
        ->assertOk()
        ->assertJsonPath('subject', 'Hi Jane Doe')
        ->assertJson(fn ($json) => $json->where('html', fn ($html) => ! str_contains($html, '<script'))->etc());
});

// --- Test email -------------------------------------------------------------

it('sends a test email of a registered template', function () {
    Mail::fake();

    $this->actingAs(emailUser('boss@x.test', admin: true))
        ->post('/admin/email-templates/welcome/test', ['email' => 'dest@x.test', 'locale' => 'en'])
        ->assertRedirect()
        ->assertSessionHas('status');
});

it('does not send a test email when the template is switched off', function () {
    app(EmailTemplateRepository::class)->save('welcome', [
        'is_active' => false,
        'subject' => ['en' => 'S', 'uk' => 'S'],
        'body_html' => ['en' => '<p>x</p>', 'uk' => '<p>x</p>'],
        'body_text' => ['en' => 'x', 'uk' => 'x'],
    ]);

    $this->actingAs(emailUser('boss@x.test', admin: true))
        ->post('/admin/email-templates/welcome/test', ['email' => 'dest@x.test', 'locale' => 'en'])
        ->assertSessionHasErrors('email');
});
