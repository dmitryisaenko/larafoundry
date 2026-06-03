<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\ActivityLog\Contracts\GeoResolver;
use Dmitryisaenko\LaraFoundry\ActivityLog\Geo\IpApiGeoResolver;
use Dmitryisaenko\LaraFoundry\ActivityLog\Jobs\RetrieveActivityGeoData;
use Dmitryisaenko\LaraFoundry\ActivityLog\Models\Activity as ActivityModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('enriches an entry with geo data when the job runs', function () {
    $this->app->bind(GeoResolver::class, fn () => new class implements GeoResolver
    {
        public function resolve(string $ip): array
        {
            return ['country' => 'Wonderland', 'city' => 'Heart City'];
        }
    });

    $entry = ActivityModel::query()->create([
        'log_name' => 'Auth',
        'description' => 'x',
        'user_ip' => '8.8.8.8',
    ]);

    (new RetrieveActivityGeoData($entry->id, '8.8.8.8'))->handle(app(GeoResolver::class));

    $entry->refresh();

    expect($entry->geo_country)->toBe('Wonderland')
        ->and($entry->geo_city)->toBe('Heart City')
        ->and($entry->geo_updated_at)->not->toBeNull();
});

it('falls back to Unknown when the job fails on an un-enriched row', function () {
    $entry = ActivityModel::query()->create([
        'log_name' => 'Auth',
        'description' => 'x',
        'user_ip' => '8.8.8.8',
    ]);

    (new RetrieveActivityGeoData($entry->id, '8.8.8.8'))->failed(new RuntimeException('boom'));

    $entry->refresh();

    expect($entry->geo_country)->toBe('Unknown')
        ->and($entry->geo_city)->toBe('Unknown');
});

it('does not clobber already-resolved geo when the job later fails', function () {
    // The row was enriched with a real country (e.g. the write succeeded once);
    // a subsequent terminal failure must NOT overwrite it with Unknown.
    $entry = ActivityModel::query()->create([
        'log_name' => 'Auth',
        'description' => 'x',
        'user_ip' => '8.8.8.8',
        'geo_country' => 'Germany',
        'geo_city' => 'Berlin',
        'geo_updated_at' => now(),
    ]);

    (new RetrieveActivityGeoData($entry->id, '8.8.8.8'))->failed(new RuntimeException('boom'));

    $entry->refresh();

    expect($entry->geo_country)->toBe('Germany')
        ->and($entry->geo_city)->toBe('Berlin');
});

it('never sends a private IP to the third party and answers locally', function () {
    Http::fake();

    $result = (new IpApiGeoResolver)->resolve('192.168.1.10');

    expect($result['country'])->toBe('Local network');
    Http::assertNothingSent();
});

it('degrades to Unknown when the geo provider errors', function () {
    Http::fake(fn () => Http::response('nope', 500));

    $result = (new IpApiGeoResolver)->resolve('8.8.8.8');

    expect($result['country'])->toBe('Unknown')
        ->and($result['city'])->toBe('Unknown');
});
