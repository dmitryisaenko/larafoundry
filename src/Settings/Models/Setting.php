<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Settings\Models;

use Dmitryisaenko\LaraFoundry\Settings\Support\SettingsRepository;
use Illuminate\Database\Eloquent\Model;

/**
 * One stored setting value — a generic key/value row scoped to app, a company or
 * a user (phase 5.1).
 *
 * Deliberately thin: the typing, defaults, allowlist and casting all live in the
 * config registry read by {@see SettingsRepository},
 * never on the model. The `value` column holds the JSON-encoded value (so a
 * boolean/number/array round-trips), which the repository decodes and casts to
 * the key's declared type. `scope_id` is a string so it fits both integer and
 * uuid primary keys; app-scope rows use the sentinel '0' so the
 * (scope, scope_id, key) unique index still bites (a NULL would not).
 */
class Setting extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'scope',
        'scope_id',
        'key',
        'value',
    ];

    /**
     * The settings table name.
     */
    public function getTable(): string
    {
        return 'larafoundry_settings';
    }
}
