<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\ActivityLog\Models\Activity as ActivityModel;
use Dmitryisaenko\LaraFoundry\Email\Models\EmailTemplate;
use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/*
 * Phase 2b controller CRUD for the marketing layer. Fail-closed integrity of the
 * transactional layer is enforced at the controller too (destroy 403s a registry
 * code), over the repository guard.
 */

beforeEach(function () {
    Cache::flush();

    config()->set('larafoundry.security.super_admin.require_otp', false);
    config()->set('larafoundry.security.super_admin.email', null);
    config()->set('larafoundry-activitylog.geo.enabled', false);

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

function mktUser(string $email = 'u@x.test', bool $admin = false): User
{
    return User::create([
        'name' => 'U',
        'email' => $email,
        'password' => 'secret-pass',
        'email_verified_at' => now(),
        'is_admin' => $admin,
    ]);
}

function mktAdmin(): User
{
    return mktUser('boss@x.test', admin: true);
}

/**
 * @return array<string, mixed>
 */
function storePayload(string $code = 'promo_launch'): array
{
    return [
        'code' => $code,
        'name' => 'Launch promo',
        'is_active' => true,
        'variables' => ['first_name', 'offer'],
        'subject' => ['en' => 'Hello {{first_name}}', 'uk' => 'Привіт'],
        'body_html' => ['en' => '<p>{{offer}} for {{first_name}}</p>', 'uk' => '<p>x</p>'],
        'body_text' => ['en' => '{{offer}}', 'uk' => 'x'],
    ];
}

// --- Pages ------------------------------------------------------------------

it('renders the create screen for a super-admin', function () {
    $this->actingAs(mktAdmin())
        ->withHeader('X-Inertia', 'true')
        ->get('/admin/email-templates/create')
        ->assertOk()
        ->assertJsonPath('component', 'Admin/EmailTemplates/Create');
});

// --- Store ------------------------------------------------------------------

it('creates a marketing template and audits it readably', function () {
    $this->actingAs(mktAdmin())
        ->post('/admin/email-templates', storePayload())
        ->assertRedirect('/admin/email-templates/promo_launch/edit');

    $this->assertDatabaseHas('larafoundry_email_templates', [
        'code' => 'promo_launch',
        'type' => 'marketing',
        'name' => 'Launch promo',
    ]);

    $entry = ActivityModel::query()->where('description', 'admin.email_template.created')->latest('id')->first();
    expect($entry)->not->toBeNull()
        ->and($entry->log_name)->toBe('admin')
        // Readable, NOT masked by the 'code' pii_redact_keys entry.
        ->and($entry->properties['template_code'] ?? null)->toBe('promo_launch')
        ->and($entry->properties['type'] ?? null)->toBe('marketing');
});

it('rejects a marketing code that collides with a registry code', function () {
    $this->actingAs(mktAdmin())
        ->post('/admin/email-templates', storePayload('welcome'))
        ->assertSessionHasErrors('code');

    expect(EmailTemplate::where('code', 'welcome')->exists())->toBeFalse();
});

it('rejects a marketing body variable outside the submitted whitelist', function () {
    $payload = storePayload();
    $payload['body_html']['en'] = '<p>{{offer}} {{secret_token}}</p>';

    $this->actingAs(mktAdmin())
        ->post('/admin/email-templates', $payload)
        ->assertSessionHasErrors('body_html.en');

    expect(EmailTemplate::where('code', 'promo_launch')->exists())->toBeFalse();
});

it('purifies a script out of a marketing body on store', function () {
    $payload = storePayload();
    $payload['body_html']['en'] = '<p>{{offer}}</p><script>alert(1)</script>';

    $this->actingAs(mktAdmin())
        ->post('/admin/email-templates', $payload)
        ->assertRedirect();

    expect(EmailTemplate::where('code', 'promo_launch')->first()->body_html_translations['en'])
        ->not->toContain('<script');
});

// --- Duplicate --------------------------------------------------------------

it('duplicates a transactional code into a marketing copy and audits it', function () {
    $this->actingAs(mktAdmin())
        ->post('/admin/email-templates/welcome/duplicate')
        ->assertRedirect('/admin/email-templates/welcome_copy/edit');

    $this->assertDatabaseHas('larafoundry_email_templates', [
        'code' => 'welcome_copy',
        'type' => 'marketing',
    ]);

    // The registry code stays transactional and un-forked (no DB row).
    expect(EmailTemplate::where('code', 'welcome')->exists())->toBeFalse();

    $entry = ActivityModel::query()->where('description', 'admin.email_template.duplicated')->latest('id')->first();
    expect($entry->properties['template_code'] ?? null)->toBe('welcome_copy')
        ->and($entry->properties['source_code'] ?? null)->toBe('welcome');
});

// --- Update marketing -------------------------------------------------------

it('updates a marketing template name, variables and body', function () {
    app(EmailTemplateRepository::class)->createMarketing([
        'code' => 'promo_launch',
        'name' => 'Launch promo',
        'variables' => ['first_name', 'offer'],
        'subject' => ['en' => 'Hi {{first_name}}', 'uk' => 'Hi'],
        'body_html' => ['en' => '<p>{{offer}}</p>', 'uk' => '<p>x</p>'],
        'body_text' => ['en' => '{{offer}}', 'uk' => 'x'],
    ]);

    $this->actingAs(mktAdmin())
        ->put('/admin/email-templates/promo_launch', [
            'is_active' => true,
            'name' => 'Renamed promo',
            'variables' => ['first_name', 'offer', 'coupon'],
            'subject' => ['en' => 'Hi {{coupon}}', 'uk' => 'Hi'],
            'body_html' => ['en' => '<p>{{coupon}}</p>', 'uk' => '<p>x</p>'],
            'body_text' => ['en' => '{{coupon}}', 'uk' => 'x'],
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('larafoundry_email_templates', [
        'code' => 'promo_launch',
        'type' => 'marketing',
        'name' => 'Renamed promo',
    ]);

    $entry = ActivityModel::query()->where('description', 'admin.email_template.updated')->latest('id')->first();
    expect($entry->properties['template_code'] ?? null)->toBe('promo_launch')
        ->and($entry->properties['type'] ?? null)->toBe('marketing');
});

it('rejects a marketing update body variable outside the submitted whitelist', function () {
    app(EmailTemplateRepository::class)->createMarketing([
        'code' => 'promo_launch',
        'name' => 'Launch promo',
        'variables' => ['first_name', 'offer'],
        'subject' => ['en' => 'Hi {{first_name}}', 'uk' => 'Hi'],
        'body_html' => ['en' => '<p>{{offer}}</p>', 'uk' => '<p>x</p>'],
        'body_text' => ['en' => '{{offer}}', 'uk' => 'x'],
    ]);

    $this->actingAs(mktAdmin())
        ->put('/admin/email-templates/promo_launch', [
            'is_active' => true,
            'name' => 'Launch promo',
            'variables' => ['first_name', 'offer'],
            'subject' => ['en' => 'Hi {{first_name}}', 'uk' => 'Hi'],
            'body_html' => ['en' => '<p>{{missing}}</p>', 'uk' => '<p>x</p>'],
            'body_text' => ['en' => 'x', 'uk' => 'x'],
        ])
        ->assertSessionHasErrors('body_html.en');
});

// --- Destroy (fail-closed at the controller) --------------------------------

it('deletes a marketing template and audits it', function () {
    app(EmailTemplateRepository::class)->createMarketing(storePayload());

    $this->actingAs(mktAdmin())
        ->delete('/admin/email-templates/promo_launch')
        ->assertRedirect('/admin/email-templates');

    $this->assertDatabaseMissing('larafoundry_email_templates', ['code' => 'promo_launch']);

    $entry = ActivityModel::query()->where('description', 'admin.email_template.deleted')->latest('id')->first();
    expect($entry->properties['template_code'] ?? null)->toBe('promo_launch');
});

it('403s deleting a transactional/registry code', function () {
    $this->actingAs(mktAdmin())
        ->delete('/admin/email-templates/welcome')
        ->assertForbidden();
});

it('404s deleting an unknown code', function () {
    $this->actingAs(mktAdmin())
        ->delete('/admin/email-templates/does_not_exist')
        ->assertNotFound();
});

// --- Preview + test-send for marketing --------------------------------------

it('previews an unsaved marketing draft with sample data', function () {
    $this->actingAs(mktAdmin())
        ->postJson('/admin/email-templates/preview-draft', [
            'locale' => 'en',
            'subject' => 'Hi {{first_name}}',
            'body_html' => '<p>{{offer}}</p><script>alert(1)</script>',
            'body_text' => '{{offer}}',
            'variables' => ['first_name', 'offer'],
        ])
        ->assertOk()
        ->assertJson(fn ($json) => $json->where('html', fn ($html) => ! str_contains($html, '<script'))->etc());
});

it('sends a test email of a marketing template', function () {
    Mail::fake();

    app(EmailTemplateRepository::class)->createMarketing(storePayload());

    $this->actingAs(mktAdmin())
        ->post('/admin/email-templates/promo_launch/test', ['email' => 'dest@x.test', 'locale' => 'en'])
        ->assertRedirect()
        ->assertSessionHas('status');
});

// --- Authorization ----------------------------------------------------------

it('forbids a non-admin from every marketing endpoint', function () {
    app(EmailTemplateRepository::class)->createMarketing(storePayload());

    $user = mktUser();

    $this->actingAs($user)->get('/admin/email-templates/create')->assertForbidden();
    $this->actingAs($user)->post('/admin/email-templates', storePayload('other_promo'))->assertForbidden();
    $this->actingAs($user)->post('/admin/email-templates/welcome/duplicate')->assertForbidden();
    $this->actingAs($user)->delete('/admin/email-templates/promo_launch')->assertForbidden();
});
