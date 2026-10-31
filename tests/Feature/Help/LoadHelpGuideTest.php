<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Help\Models\HelpArticle;
use Dmitryisaenko\LaraFoundry\Help\Models\HelpSection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('larafoundry.locale.available', ['en', 'uk']);
    config()->set('larafoundry.locale.default', 'en');
    config()->set('larafoundry-help.source_locale', 'uk');

    $this->guide = sys_get_temp_dir().DIRECTORY_SEPARATOR.'help-guide-'.uniqid();
    config()->set('larafoundry-help.guide_path', $this->guide);
    mkdir($this->guide.DIRECTORY_SEPARATOR.'start', 0777, true);

    $this->section = HelpSection::query()->create([
        'key' => 'start',
        'title_translations' => ['uk' => 'Початок'],
        'blurb_translations' => [],
    ]);
});

afterEach(function () {
    foreach (glob($this->guide.'/start/*') ?: [] as $file) {
        unlink($file);
    }

    @rmdir($this->guide.'/start');
    @rmdir($this->guide);
});

function guideFile(string $dir, string $name, array $data): void
{
    file_put_contents($dir.DIRECTORY_SEPARATOR.'start'.DIRECTORY_SEPARATOR.$name, '<?php return '.var_export($data, true).';');
}

function guideArticle(string $heading): array
{
    return [
        'heading' => ['uk' => $heading, 'en' => $heading.' en'],
        'body' => ['uk' => '<p>'.$heading.'<script>x</script></p>', 'en' => '<p>en</p>'],
    ];
}

it('loads the files by their numbers, cleaned', function () {
    guideFile($this->guide, '02-second.php', guideArticle('Друга'));
    guideFile($this->guide, '01-first.php', guideArticle('Перша') + ['mobile' => ['uk' => '<p>m</p>', 'en' => '<p>m</p>']]);

    $this->artisan('larafoundry:help-load-guide', ['section' => 'start'])->assertSuccessful();

    $rows = HelpArticle::query()->orderBy('position')->get();

    expect($rows->pluck('position')->all())->toBe([10, 20])
        ->and($rows[0]->heading_translations['uk'])->toBe('Перша')
        ->and($rows[0]->body_translations['uk'])->not->toContain('script')
        ->and($rows[0]->mobile_body_translations)->toBe(['uk' => '<p>m</p>', 'en' => '<p>m</p>'])
        ->and($rows[1]->mobile_body_translations)->toBeNull();
});

it('updates in place on a re-run and prunes only when asked', function () {
    guideFile($this->guide, '01-first.php', guideArticle('Перша'));
    $this->section->articles()->create(['heading_translations' => ['uk' => 'Ручна'], 'body_translations' => [], 'position' => 50]);

    $this->artisan('larafoundry:help-load-guide', ['section' => 'start'])->assertSuccessful();
    guideFile($this->guide, '01-first.php', guideArticle('Перша нова'));
    $this->artisan('larafoundry:help-load-guide', ['section' => 'start'])->assertSuccessful();

    expect(HelpArticle::query()->count())->toBe(2)
        ->and(HelpArticle::query()->where('position', 10)->first()->heading_translations['uk'])->toBe('Перша нова');

    $this->artisan('larafoundry:help-load-guide', ['section' => 'start', '--prune' => true, '--dry-run' => true])->assertSuccessful();
    expect(HelpArticle::query()->count())->toBe(2);

    $this->artisan('larafoundry:help-load-guide', ['section' => 'start', '--prune' => true])->assertSuccessful();
    expect(HelpArticle::query()->pluck('position')->all())->toBe([10]);
});

it('loads nothing when one file is broken', function () {
    guideFile($this->guide, '01-first.php', guideArticle('Перша'));
    guideFile($this->guide, '02-second.php', ['heading' => ['uk' => 'Без англійської'], 'body' => ['uk' => '<p>x</p>']]);

    $this->artisan('larafoundry:help-load-guide', ['section' => 'start'])->assertFailed();

    expect(HelpArticle::query()->count())->toBe(0);
});

it('refuses a section whose articles share a position', function () {
    guideFile($this->guide, '01-first.php', guideArticle('Перша'));
    $this->section->articles()->create(['heading_translations' => [], 'body_translations' => [], 'position' => 10]);
    $this->section->articles()->create(['heading_translations' => [], 'body_translations' => [], 'position' => 10]);

    $this->artisan('larafoundry:help-load-guide', ['section' => 'start'])->assertFailed();
});
