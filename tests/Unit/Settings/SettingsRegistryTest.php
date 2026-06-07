<?php

declare(strict_types=1);

use Dmitryisaenko\LaraFoundry\Settings\Support\SettingsRepository;

beforeEach(function () {
    config()->set('larafoundry.settings', [
        'support_email' => ['scope' => 'app', 'label' => 'Support email', 'type' => 'string', 'default' => null, 'validation' => ['nullable', 'email']],
        'count' => ['scope' => 'app', 'type' => 'integer', 'default' => 0, 'validation' => ['integer']],
        'tags' => ['scope' => 'app', 'type' => 'array', 'default' => [], 'validation' => ['array']],
        'flag' => ['scope' => 'user', 'type' => 'boolean', 'default' => true, 'validation' => ['boolean']],
        'consent' => ['scope' => 'user', 'type' => 'boolean', 'default' => false, 'form' => false],
    ]);

    $this->repo = new SettingsRepository;
});

it('reports registration and scope', function () {
    expect($this->repo->isRegistered('flag'))->toBeTrue()
        ->and($this->repo->isRegistered('nope'))->toBeFalse()
        ->and($this->repo->scopeOf('support_email'))->toBe('app')
        ->and($this->repo->scopeOf('flag'))->toBe('user')
        ->and($this->repo->scopeOf('nope'))->toBeNull();
});

it('casts values to their declared type', function () {
    expect($this->repo->cast('flag', '1'))->toBeTrue()
        ->and($this->repo->cast('flag', '0'))->toBeFalse()
        ->and($this->repo->cast('flag', 'garbage'))->toBeFalse()
        ->and($this->repo->cast('count', '42'))->toBe(42)
        ->and($this->repo->cast('support_email', 123))->toBe('123')
        ->and($this->repo->cast('support_email', null))->toBeNull()
        ->and($this->repo->cast('tags', ['a', 'b']))->toBe(['a', 'b'])
        ->and($this->repo->cast('tags', '["a"]'))->toBe(['a']);
});

it('builds a form schema excluding non-form keys', function () {
    $schema = collect($this->repo->schemaForScope('user'))->keyBy('key');

    expect($schema)->toHaveKey('flag')
        ->and($schema)->not->toHaveKey('consent')
        ->and($schema['flag']['type'])->toBe('boolean');
});

it('carries the label into the schema, falling back to the key', function () {
    $schema = collect($this->repo->schemaForScope('app'))->keyBy('key');

    expect($schema['support_email']['label'])->toBe('Support email')
        ->and($schema['count']['label'])->toBe('count');
});
