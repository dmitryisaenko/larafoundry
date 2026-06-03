<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Media\Events\FileUploaded;
use Dmitryisaenko\LaraFoundry\Tenancy\Actions\CreateCompanyAction;
use Dmitryisaenko\LaraFoundry\Tests\Fixtures\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

/*
 * Phase 2.4: the company-creation wizard's logo step now stores through the
 * media action onto the CONFIGURED disk (not a hardcoded public disk), with a
 * generated filename, and announces a FileUploaded event.
 */

function logoOwner(): User
{
    return User::create([
        'name' => 'Owner',
        'email' => 'owner'.uniqid().'@x.test',
        'password' => 'secret-pass',
        'email_verified_at' => now(),
    ]);
}

it('stores an uploaded logo on the configured media disk with a uuid filename', function () {
    Storage::fake('public');
    $owner = logoOwner();
    app(CreateCompanyAction::class)->execute($owner, ['name' => 'Acme']);

    $this->actingAs($owner)
        ->post('/companies/create/step1', ['name' => 'Acme'])
        ->assertRedirect();

    $this->actingAs($owner)
        ->post('/companies/create/step2', [
            'logo' => UploadedFile::fake()->image('brand.png', 120, 120),
        ])
        ->assertRedirect();

    $company = $owner->fresh()->getActiveCompany();

    expect($company->logo_path)->toMatch('#^company-logos/\d{4}/\d{2}/[0-9a-f\-]{36}\.jpg$#')
        ->and($company->logo)->toEndWith('.jpg');

    Storage::disk('public')->assertExists($company->logo_path);
});

it('exposes a resolvable logo_url through the configured disk', function () {
    Storage::fake('public');
    $owner = logoOwner();

    $this->actingAs($owner)->post('/companies/create/step1', ['name' => 'Acme'])->assertRedirect();
    $this->actingAs($owner)->post('/companies/create/step2', [
        'logo' => UploadedFile::fake()->image('brand.png', 60, 60),
    ])->assertRedirect();

    $company = $owner->fresh()->getActiveCompany();

    expect($company->logo_url)->toContain($company->logo_path)
        ->and($company->logo_url)->not->toStartWith('data:');
});

it('fires FileUploaded with the company_logo context on upload', function () {
    Storage::fake('public');
    Event::fake([FileUploaded::class]);
    $owner = logoOwner();

    $this->actingAs($owner)->post('/companies/create/step1', ['name' => 'Acme'])->assertRedirect();
    $this->actingAs($owner)->post('/companies/create/step2', [
        'logo' => UploadedFile::fake()->image('brand.png', 50, 50),
    ])->assertRedirect();

    Event::assertDispatched(FileUploaded::class, fn ($e) => $e->context === 'company_logo');
});

it('rejects an oversized logo via the config-driven limit', function () {
    Storage::fake('public');
    config()->set('larafoundry-media.max_upload_kb', 100);
    $owner = logoOwner();

    $this->actingAs($owner)->post('/companies/create/step1', ['name' => 'Acme'])->assertRedirect();

    $this->actingAs($owner)
        ->post('/companies/create/step2', [
            'logo' => UploadedFile::fake()->image('huge.png', 50, 50)->size(500),
        ])
        ->assertSessionHasErrors('logo');
});

it('rejects a non-image upload as a logo', function () {
    Storage::fake('public');
    $owner = logoOwner();

    $this->actingAs($owner)->post('/companies/create/step1', ['name' => 'Acme'])->assertRedirect();

    $this->actingAs($owner)
        ->post('/companies/create/step2', [
            'logo' => UploadedFile::fake()->create('evil.pdf', 4, 'application/pdf'),
        ])
        ->assertSessionHasErrors('logo');
});
