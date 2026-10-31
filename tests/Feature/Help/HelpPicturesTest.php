<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Help\Models\HelpImage;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Storage::fake('local');

    config()->set('larafoundry.security.super_admin.require_otp', false);
    config()->set('larafoundry.security.super_admin.email', null);
    config()->set('larafoundry.locale.available', ['en', 'uk']);
});

function helpPictureAdmin(): User
{
    return User::create([
        'name' => 'U',
        'email' => 'boss@x.test',
        'password' => 'secret-pass',
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);
}

/** A real png the GD decoder accepts, big enough that webp comes out smaller. */
function helpPng(): UploadedFile
{
    $image = imagecreatetruecolor(300, 200);
    imagefilledrectangle($image, 0, 0, 299, 199, imagecolorallocate($image, 30, 120, 200));

    for ($i = 0; $i < 400; $i++) {
        imagesetpixel($image, random_int(0, 299), random_int(0, 199), imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255)));
    }

    ob_start();
    imagepng($image);
    $bytes = (string) ob_get_clean();

    $path = tempnam(sys_get_temp_dir(), 'help').'.png';
    file_put_contents($path, $bytes);

    return new UploadedFile($path, 'Screen Shot.png', 'image/png', null, true);
}

it('stores an upload as a record with a free reference', function () {
    $admin = helpPictureAdmin();

    $first = $this->actingAs($admin)->post('/admin/help-articles/pictures', ['image' => helpPng(), 'ref' => 'Screen Shot'], ['Accept' => 'application/json']);
    $second = $this->actingAs($admin)->post('/admin/help-articles/pictures', ['image' => helpPng(), 'ref' => 'Screen Shot'], ['Accept' => 'application/json']);

    $first->assertOk()->assertJsonPath('picture.ref', 'screen-shot')->assertJsonPath('picture.marker', '[[img:screen-shot]]');
    $second->assertOk()->assertJsonPath('picture.ref', 'screen-shot-2');

    $image = HelpImage::query()->firstOrFail();

    expect($image->file)->toMatch('/^[0-9a-f-]{36}\.(webp|png)$/');
    Storage::disk('local')->assertExists('larafoundry-help/'.$image->file);
});

it('converts a png to webp when that is smaller', function () {
    if (! function_exists('imagewebp')) {
        $this->markTestSkipped('this PHP has no webp support');
    }

    $this->actingAs(helpPictureAdmin())
        ->post('/admin/help-articles/pictures', ['image' => helpPng()], ['Accept' => 'application/json'])
        ->assertOk();

    expect(HelpImage::query()->value('file'))->toEndWith('.webp');
});

it('refuses a file whose contents are not a picture', function () {
    // A real file, not UploadedFile::fake(): a fake reports the type its NAME claims,
    // while a real request is typed by its contents - which is what is being tested.
    $path = tempnam(sys_get_temp_dir(), 'help').'.png';
    file_put_contents($path, 'not a picture at all');
    $fake = new UploadedFile($path, 'shot.png', 'image/png', null, true);

    $this->actingAs(helpPictureAdmin())
        ->post('/admin/help-articles/pictures', ['image' => $fake], ['Accept' => 'application/json'])
        ->assertUnprocessable();

    expect(HelpImage::query()->count())->toBe(0);
});

it('serves a picture to a guest, cached for good', function () {
    $name = '0b4f3c1e-2a7d-4e55-9a10-1d2c3b4a5f60.webp';
    Storage::disk('local')->put('larafoundry-help/'.$name, 'bytes');

    $response = $this->get('/help/pictures/'.$name);

    $response->assertOk();
    expect($response->headers->get('Content-Type'))->toBe('image/webp')
        ->and($response->headers->get('Cache-Control'))->toContain('immutable')
        // Cached publicly: a session cookie on it would be handed to everyone after.
        ->and($response->headers->getCookies())->toBe([]);
});

it('refuses a name that is not one an upload writes, before touching the disk', function () {
    Storage::disk('local')->put('larafoundry-help/notes.txt', 'secret');
    Storage::disk('local')->put('secret.webp', 'secret');

    $this->get('/help/pictures/notes.txt')->assertNotFound();
    $this->get('/help/pictures/..%2Fsecret.webp')->assertNotFound();
    $this->get('/help/pictures/0b4f3c1e-2a7d-4e55-9a10-1d2c3b4a5f60.webp')->assertNotFound();

    // `$` alone also matches before a final line break, and the disk then throws.
    Storage::disk('local')->put('larafoundry-help/0b4f3c1e-2a7d-4e55-9a10-1d2c3b4a5f60.webp', 'bytes');
    $this->get('/help/pictures/0b4f3c1e-2a7d-4e55-9a10-1d2c3b4a5f60.webp%0A')->assertNotFound();
});

it('refuses a picture too large to open, before decoding it', function () {
    $image = imagecreatetruecolor(8001, 1);

    ob_start();
    imagepng($image);
    $path = tempnam(sys_get_temp_dir(), 'help').'.png';
    file_put_contents($path, (string) ob_get_clean());

    $this->actingAs(helpPictureAdmin())
        ->post('/admin/help-articles/pictures', ['image' => new UploadedFile($path, 'wide.png', 'image/png', null, true)], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('image');

    expect(HelpImage::query()->count())->toBe(0);
});

it('refuses a picture with too many pixels even when each side fits (review 2026-10-31)', function () {
    // 5000 x 4001 = just over 20 million: both sides under 8000, decoding it would not be.
    $image = imagecreate(5000, 4001);
    imagecolorallocate($image, 255, 255, 255);

    ob_start();
    imagepng($image);
    $path = tempnam(sys_get_temp_dir(), 'help').'.png';
    file_put_contents($path, (string) ob_get_clean());

    $this->actingAs(helpPictureAdmin())
        ->post('/admin/help-articles/pictures', ['image' => new UploadedFile($path, 'big.png', 'image/png', null, true)], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.image.0', __('larafoundry::help.picture_pixels', ['max' => 20]));

    expect(HelpImage::query()->count())->toBe(0);
});

it('renames a picture and deletes its file with it', function () {
    $admin = helpPictureAdmin();

    $this->actingAs($admin)->post('/admin/help-articles/pictures', ['image' => helpPng()], ['Accept' => 'application/json'])->assertOk();
    $image = HelpImage::query()->firstOrFail();

    $this->actingAs($admin)
        ->patchJson("/admin/help-articles/pictures/{$image->id}", ['ref' => 'menu', 'caption' => ['en' => 'The menu'], 'max_width' => 400])
        ->assertOk()
        ->assertJsonPath('picture.marker', '[[img:menu]]');

    $this->actingAs($admin)
        ->patchJson("/admin/help-articles/pictures/{$image->id}", ['ref' => 'menu', 'max_width' => 50])
        ->assertUnprocessable();

    $this->actingAs($admin)->deleteJson("/admin/help-articles/pictures/{$image->id}")->assertOk();

    expect(HelpImage::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing('larafoundry-help/'.$image->file);
});
