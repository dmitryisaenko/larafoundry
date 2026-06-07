<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Settings\Facades;

use Dmitryisaenko\LaraFoundry\Settings\Support\SettingsRepository;
use Illuminate\Support\Facades\Facade;

/**
 * Facade over {@see SettingsRepository} — the ergonomic `Settings::get(...)` API
 * (phase 5.1).
 *
 * @method static mixed get(string $key, int|string|null $scopeId = null, mixed $default = null)
 * @method static bool has(string $key, int|string|null $scopeId = null)
 * @method static void set(string $key, mixed $value, int|string|null $scopeId = null)
 * @method static array allForScope(string $scope, int|string|null $scopeId = null)
 * @method static array publicSettings()
 * @method static array schemaForScope(string $scope)
 * @method static array registry()
 * @method static array|null definition(string $key)
 * @method static bool isRegistered(string $key)
 * @method static string|null scopeOf(string $key)
 * @method static mixed cast(string $key, mixed $value)
 *
 * @see SettingsRepository
 */
class Settings extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return SettingsRepository::class;
    }
}
