<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Help\Contracts\HelpTranslator;
use Dmitryisaenko\LaraFoundry\Help\Exceptions\HelpTranslationRefused;
use Dmitryisaenko\LaraFoundry\Help\Models\HelpArticle;
use Dmitryisaenko\LaraFoundry\Help\Models\HelpSection;
use Dmitryisaenko\LaraFoundry\Help\Support\HelpEditor;
use Dmitryisaenko\LaraFoundry\Navigation\Providers\AdminMenuProvider;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();

    config()->set('larafoundry.security.super_admin.require_otp', false);
    config()->set('larafoundry.security.super_admin.email', null);
    config()->set('larafoundry.locale.available', ['en', 'uk']);
    config()->set('larafoundry.locale.default', 'en');
    config()->set('larafoundry-help.source_locale', 'uk');
});

function helpEditorUser(bool $admin = true): User
{
    return User::query()->firstOrCreate(['email' => $admin ? 'boss@x.test' : 'u@x.test'], [
        'name' => 'U',
        'password' => 'secret-pass',
        'email_verified_at' => now(),
        'is_admin' => $admin,
    ]);
}

function helpEditorSection(string $key = 'start', int $position = 10): HelpSection
{
    return HelpSection::query()->create([
        'key' => $key,
        'title_translations' => ['uk' => 'Розділ'],
        'blurb_translations' => [],
        'position' => $position,
    ]);
}

it('renders the editor for a super-admin', function () {
    $this->actingAs(helpEditorUser())
        ->withHeader('X-Inertia', 'true')
        ->get('/admin/help-articles')
        ->assertOk()
        ->assertJsonPath('component', 'Admin/Help/Editor')
        ->assertJsonPath('props.locales', ['uk', 'en'])
        ->assertJsonPath('props.source', 'uk')
        ->assertJsonPath('props.can_translate', false);
});

it('keeps everyone else out of the editor', function () {
    $this->actingAs(helpEditorUser(admin: false))->get('/admin/help-articles')->assertForbidden();
    $this->actingAs(helpEditorUser(admin: false))->postJson('/admin/help-articles/sections', [])->assertForbidden();
});

it('creates a section at the end and keeps only the help locales', function () {
    helpEditorSection('first', 30);

    $this->actingAs(helpEditorUser())
        ->postJson('/admin/help-articles/sections', [
            'key' => 'billing',
            'title' => ['uk' => '<b>Оплата</b>', 'en' => '  ', 'pl' => 'Płatności'],
        ])
        ->assertOk()
        ->assertJsonPath('section.key', 'billing')
        ->assertJsonPath('section.title', ['uk' => 'Оплата']);

    expect(HelpSection::query()->where('key', 'billing')->value('position'))->toBe(40);
});

it('refuses a section key that is not an address', function () {
    $this->actingAs(helpEditorUser())
        ->postJson('/admin/help-articles/sections', ['key' => 'Bad Key', 'title' => ['uk' => 'x']])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('key');

    // `$` alone lets a final line break through. A request has it trimmed off on the way in,
    // so the rule is asked directly: it must hold on its own.
    expect(Validator::make(['key' => "start\n", 'title' => ['uk' => 'x']], HelpEditor::sectionRules(null))->errors()->has('key'))->toBeTrue();
});

it('cleans an article body and drops a blank one', function () {
    $section = helpEditorSection();

    $this->actingAs(helpEditorUser())
        ->postJson("/admin/help-articles/sections/{$section->id}/articles", [
            'heading' => ['uk' => 'Стаття'],
            'body' => ['uk' => '<p>Текст<script>alert(1)</script></p>', 'en' => '<p></p>'],
        ])
        ->assertOk();

    $article = HelpArticle::query()->firstOrFail();

    expect($article->body_translations)->toHaveKey('uk')
        ->and($article->body_translations)->not->toHaveKey('en')
        ->and($article->body_translations['uk'])->not->toContain('script')
        ->and($article->mobile_body_translations)->toBeNull();
});

it('keeps the phone text when a save does not carry it', function () {
    $section = helpEditorSection();
    $article = $section->articles()->create([
        'heading_translations' => ['uk' => 'H'],
        'body_translations' => ['uk' => '<p>desk</p>'],
        'mobile_body_translations' => ['uk' => '<p>phone</p>'],
        'position' => 10,
    ]);

    $this->actingAs(helpEditorUser())
        ->patchJson("/admin/help-articles/articles/{$article->id}", [
            'heading' => ['uk' => 'H2'],
            'body' => ['uk' => '<p>desk 2</p>'],
        ])
        ->assertOk();

    expect($article->fresh()->mobile_body_translations)->toBe(['uk' => '<p>phone</p>']);
});

it('saves an article whose desktop text is still empty', function () {
    $section = helpEditorSection();
    $article = $section->articles()->create([
        'heading_translations' => ['uk' => 'H'],
        'body_translations' => [],
        'position' => 10,
    ]);

    // What the editor sends for a new article: the empty text came back as `{}`.
    $this->actingAs(helpEditorUser())
        ->patchJson("/admin/help-articles/articles/{$article->id}", [
            'heading' => ['uk' => 'H2'],
            'body' => (object) [],
            'body_mobile' => ['uk' => '<p>phone</p>'],
        ])
        ->assertOk();

    expect($article->fresh()->heading_translations)->toBe(['uk' => 'H2'])
        ->and($article->fresh()->mobile_body_translations)->toBe(['uk' => '<p>phone</p>']);
});

it('reorders sections, and articles only inside their own section', function () {
    $a = helpEditorSection('a', 10);
    $b = helpEditorSection('b', 20);
    $mine = $a->articles()->create(['heading_translations' => [], 'body_translations' => [], 'position' => 10]);
    $other = $b->articles()->create(['heading_translations' => [], 'body_translations' => [], 'position' => 10]);
    $mine2 = $a->articles()->create(['heading_translations' => [], 'body_translations' => [], 'position' => 20]);

    $this->actingAs(helpEditorUser())
        ->postJson('/admin/help-articles/sections/order', ['ids' => [$b->id, $a->id]])
        ->assertOk();

    expect($b->fresh()->position)->toBe(10)->and($a->fresh()->position)->toBe(20);

    $this->actingAs(helpEditorUser())
        ->postJson("/admin/help-articles/sections/{$a->id}/articles/order", ['ids' => [$other->id, $mine2->id, $mine->id]])
        ->assertOk();

    expect($mine2->fresh()->position)->toBe(20)
        ->and($mine->fresh()->position)->toBe(30)
        ->and($other->fresh()->position)->toBe(10)
        ->and($other->fresh()->help_section_id)->toBe($b->id);
});

it('deletes a section with its articles', function () {
    $section = helpEditorSection();
    $section->articles()->create(['heading_translations' => [], 'body_translations' => [], 'position' => 10]);

    $this->actingAs(helpEditorUser())->deleteJson("/admin/help-articles/sections/{$section->id}")->assertOk();

    expect(HelpSection::query()->count())->toBe(0)->and(HelpArticle::query()->count())->toBe(0);
});

it('has no translation until a host binds a translator', function () {
    $this->actingAs(helpEditorUser())
        ->postJson('/admin/help-articles/translate', ['text' => 'Текст', 'to' => 'en'])
        ->assertNotFound();
});

it('answers a translation without saving it', function () {
    app()->bind(HelpTranslator::class, fn () => new class implements HelpTranslator
    {
        public function translate(string $text, string $to, string $from): string
        {
            return "[{$from}->{$to}] ".$text;
        }
    });

    $this->actingAs(helpEditorUser())
        ->postJson('/admin/help-articles/translate', ['text' => 'Текст [[img:a]]', 'to' => 'en'])
        ->assertOk()
        ->assertJsonPath('text', '[uk->en] Текст [[img:a]]');

    // Never into the source language.
    $this->actingAs(helpEditorUser())
        ->postJson('/admin/help-articles/translate', ['text' => 'x', 'to' => 'uk'])
        ->assertUnprocessable();
});

it('refuses a translation that lost a picture, counting copies', function () {
    app()->bind(HelpTranslator::class, fn () => new class implements HelpTranslator
    {
        public function translate(string $text, string $to, string $from): string
        {
            return 'Text [[img:A]]';
        }
    });

    $this->actingAs(helpEditorUser())
        ->postJson('/admin/help-articles/translate', ['text' => 'Текст [[img:a]] і ще [[img:a]]', 'to' => 'en'])
        ->assertUnprocessable()
        ->assertJsonFragment(['message' => __('larafoundry::help.lost_pictures', ['markers' => '[[img:a]]'])]);
});

it('passes a host refusal on to the operator', function () {
    app()->bind(HelpTranslator::class, fn () => new class implements HelpTranslator
    {
        public function translate(string $text, string $to, string $from): string
        {
            throw new HelpTranslationRefused('lost a price');
        }
    });

    $this->actingAs(helpEditorUser())
        ->postJson('/admin/help-articles/translate', ['text' => 'x', 'to' => 'en'])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'lost a price');
});

it('lists the help in the admin menu only while the editor is on', function () {
    $menu = fn () => collect(app(AdminMenuProvider::class)->getMenuItems('admin'))
        ->map(fn ($item) => $item->labelKey)
        ->all();

    expect($menu())->toContain('Help');

    config()->set('larafoundry-help.admin.enabled', false);

    expect($menu())->not->toContain('Help');
});

it('does not register the editor while it is switched off', function () {
    config()->set('larafoundry-help.admin.enabled', false);

    $router = new Router(app('events'), app());
    Route::swap($router);

    require __DIR__.'/../../../routes/admin.php';
    $router->getRoutes()->refreshNameLookups();

    expect($router->getRoutes()->getByName('admin.help-articles.index'))->toBeNull()
        ->and($router->getRoutes()->getByName('admin.legal-pages.index'))->not->toBeNull();
});
