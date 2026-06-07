<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Settings\Support;

use Dmitryisaenko\LaraFoundry\Settings\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * The generic settings store: a typed, allowlisted key/value layer over the
 * `larafoundry_settings` table (phase 5.1).
 *
 * Every key is declared in `config('larafoundry.settings')` with a scope
 * (app|company|user), a type, a default, a validation rule and a `form` flag.
 * Reads and writes are fail-closed — an unregistered key returns the supplied
 * default on read and is rejected on write, so the store can never hold an
 * arbitrary key the app does not know about. Scope context (which company/user a
 * value belongs to) is resolved server-side from the authenticated user / active
 * company, NEVER taken from request input — the caller can override it
 * explicitly, but the HTTP layer always lets it resolve from context.
 *
 * The per-scope value map is cached (file/database driver — no Redis) and busted
 * on write, so a settings read is one cache hit, not a query per key.
 */
class SettingsRepository
{
    /**
     * The registry of declared settings: key => {scope, type, default, ...}.
     *
     * @return array<string, array<string, mixed>>
     */
    public function registry(): array
    {
        $registry = config('larafoundry.settings', []);

        return is_array($registry) ? $registry : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function definition(string $key): ?array
    {
        $definition = $this->registry()[$key] ?? null;

        return is_array($definition) ? $definition : null;
    }

    public function isRegistered(string $key): bool
    {
        return $this->definition($key) !== null;
    }

    /**
     * The scope a key belongs to (app|company|user), or null when unknown.
     */
    public function scopeOf(string $key): ?string
    {
        $scope = $this->definition($key)['scope'] ?? null;

        return is_string($scope) ? $scope : null;
    }

    /**
     * Read a setting, cast to its declared type.
     *
     * Fail-closed: an unregistered key returns $default. A registered key with no
     * stored value falls back to the caller's $default, then the registry default.
     * For user/company keys with no resolvable scope (no auth / no active
     * company), returns the default rather than guessing.
     */
    public function get(string $key, int|string|null $scopeId = null, mixed $default = null): mixed
    {
        $definition = $this->definition($key);

        if ($definition === null) {
            return $default;
        }

        $scope = (string) $definition['scope'];
        $resolved = $this->resolveScopeId($scope, $scopeId);

        $fallback = $default ?? ($definition['default'] ?? null);

        if ($resolved === null) {
            return $fallback;
        }

        $stored = $this->storedForScope($scope, $resolved);

        return array_key_exists($key, $stored)
            ? $this->cast($key, $stored[$key])
            : $fallback;
    }

    /**
     * Whether a value is EXPLICITLY stored for a key in its resolved scope.
     *
     * Distinct from {@see get()}: get returns the registry default when nothing is
     * stored, so it cannot tell "set to the default" from "never decided". The
     * consent flow (phase 5.3) needs that distinction — a `cookie_consent` of false
     * could mean "chose necessary-only" or "has not chosen yet". Fail-closed: an
     * unregistered key, or a user/company scope with no context, returns false.
     */
    public function has(string $key, int|string|null $scopeId = null): bool
    {
        $definition = $this->definition($key);

        if ($definition === null) {
            return false;
        }

        $scope = (string) $definition['scope'];
        $resolved = $this->resolveScopeId($scope, $scopeId);

        if ($resolved === null) {
            return false;
        }

        return array_key_exists($key, $this->storedForScope($scope, $resolved));
    }

    /**
     * Write a setting after validating it against the registry.
     *
     * Throws on an unregistered key (fail-closed), a value that fails the key's
     * validation rule, or a user/company key with no resolvable scope context.
     */
    public function set(string $key, mixed $value, int|string|null $scopeId = null): void
    {
        $definition = $this->definition($key);

        if ($definition === null) {
            throw new RuntimeException("Cannot write unregistered setting [{$key}].");
        }

        $rules = $definition['validation'] ?? [];
        if ($rules !== []) {
            Validator::make(['value' => $value], ['value' => $rules])->validate();
        }

        $scope = (string) $definition['scope'];
        $resolved = $this->resolveScopeId($scope, $scopeId);

        if ($resolved === null) {
            throw new RuntimeException("No scope context to write setting [{$key}].");
        }

        Setting::query()->updateOrCreate(
            ['scope' => $scope, 'scope_id' => (string) $resolved, 'key' => $key],
            ['value' => json_encode($this->cast($key, $value))],
        );

        $this->forget($scope, $resolved);
    }

    /**
     * Every registered key of a scope with its resolved value (stored or default).
     *
     * @return array<string, mixed>
     */
    public function allForScope(string $scope, int|string|null $scopeId = null): array
    {
        $resolved = $this->resolveScopeId($scope, $scopeId);
        $stored = $resolved === null ? [] : $this->storedForScope($scope, $resolved);

        $values = [];
        foreach ($this->registry() as $key => $definition) {
            if (($definition['scope'] ?? null) !== $scope) {
                continue;
            }

            $values[$key] = array_key_exists($key, $stored)
                ? $this->cast($key, $stored[$key])
                : ($definition['default'] ?? null);
        }

        return $values;
    }

    /**
     * App-scope settings marked `public`, resolved — safe to share with the
     * frontend (the host wires them into Inertia::share).
     *
     * @return array<string, mixed>
     */
    public function publicSettings(): array
    {
        $values = [];
        foreach ($this->registry() as $key => $definition) {
            if (($definition['scope'] ?? null) === 'app' && ($definition['public'] ?? false)) {
                $values[$key] = $this->get($key);
            }
        }

        return $values;
    }

    /**
     * The user-editable keys of a scope, shaped for the frontend form: key, type
     * and enum options. Keys flagged `form => false` (e.g. consent flags written
     * by the phase-5.3 flow) are excluded.
     *
     * @return array<int, array<string, mixed>>
     */
    public function schemaForScope(string $scope): array
    {
        $schema = [];
        foreach ($this->registry() as $key => $definition) {
            if (($definition['scope'] ?? null) !== $scope) {
                continue;
            }
            if (! ($definition['form'] ?? true)) {
                continue;
            }

            $schema[] = [
                'key' => $key,
                'label' => $definition['label'] ?? $key,
                'type' => $definition['type'] ?? 'string',
                'options' => array_values($definition['in'] ?? []),
            ];
        }

        return $schema;
    }

    /**
     * Cast a raw (json-decoded) value to the key's declared type.
     */
    public function cast(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $type = $this->definition($key)['type'] ?? 'string';

        return match ($type) {
            // Unrecognised values fall to false (fail-closed) rather than PHP's
            // truthiness, so corrupted/legacy data never flips a flag on.
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            'integer' => (int) $value,
            'float' => (float) $value,
            'array', 'json' => is_array($value) ? $value : (json_decode((string) $value, true) ?? []),
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    /**
     * The stored (json-decoded) value map for a scope, cached and busted on write.
     *
     * @return array<string, mixed>
     */
    protected function storedForScope(string $scope, int|string $scopeId): array
    {
        return Cache::rememberForever($this->cacheKey($scope, $scopeId), function () use ($scope, $scopeId) {
            $rows = Setting::query()
                ->where('scope', $scope)
                ->where('scope_id', (string) $scopeId)
                ->pluck('value', 'key')
                ->all();

            $decoded = [];
            foreach ($rows as $key => $raw) {
                $decoded[$key] = json_decode((string) $raw, true);
            }

            return $decoded;
        });
    }

    protected function forget(string $scope, int|string $scopeId): void
    {
        Cache::forget($this->cacheKey($scope, $scopeId));
    }

    protected function cacheKey(string $scope, int|string $scopeId): string
    {
        return 'larafoundry.settings.'.$scope.'.'.$scopeId;
    }

    /**
     * Resolve which entity a scope's value belongs to.
     *
     * app   → the '0' sentinel (one global row per key);
     * user  → the explicit id, else the authenticated user;
     * company → the explicit id, else the user's active company.
     * Returns null when a user/company scope has no context (no auth / no active
     * company) — the caller decides whether that is a default or an error.
     */
    protected function resolveScopeId(string $scope, int|string|null $scopeId): int|string|null
    {
        if ($scope === 'app') {
            return '0';
        }

        if ($scopeId !== null) {
            return $scopeId;
        }

        $user = auth()->user();

        if ($scope === 'user') {
            return $user?->getAuthIdentifier();
        }

        if ($scope === 'company') {
            return $user !== null && method_exists($user, 'getCurrentCompanyId')
                ? $user->getCurrentCompanyId()
                : null;
        }

        return null;
    }
}
