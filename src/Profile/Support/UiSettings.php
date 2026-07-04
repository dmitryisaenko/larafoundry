<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Support;

/**
 * The allowlist gate for per-user UI preferences (`users.ui_settings`), phase
 * 5.1.
 *
 * The donor wrote any client-supplied key/value straight into the JSON column
 * (recon finding #2): an attacker could bloat the row or smuggle an unexpected
 * flag the UI later trusts. Here the column is fail-closed — only keys declared
 * in `larafoundry.profile.ui_settings` are readable or writable, each with a
 * type (so the stored value is cast, not trusted) and an optional `in` enum.
 *
 * This is the small, typed cousin of the generic Settings module (sub-phase B):
 * ui_settings stays user-scoped and lightweight (theme, density…), while Settings
 * carries the app/company/user key-value store.
 */
class UiSettings
{
    /**
     * The registered preference definitions: key => {type, default, in?}.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function registry(): array
    {
        $registry = config('larafoundry.profile.ui_settings', []);

        return is_array($registry) ? $registry : [];
    }

    public static function isAllowed(string $key): bool
    {
        return array_key_exists($key, self::registry());
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function definition(string $key): ?array
    {
        $definition = self::registry()[$key] ?? null;

        return is_array($definition) ? $definition : null;
    }

    /**
     * Cast a raw value to the type declared for the key (defaults to string).
     */
    public static function cast(string $key, mixed $value): mixed
    {
        $type = self::definition($key)['type'] ?? 'string';

        return match ($type) {
            // Unrecognised values fall to false (fail-closed), not PHP truthiness.
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false,
            'integer' => (int) $value,
            'float' => (float) $value,
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    /**
     * The user's resolved preferences: every registered key, the stored value
     * (cast) when present, else the declared default. Non-allowlisted keys that
     * may linger in the column are dropped — the read is fail-closed too.
     *
     * @return array<string, mixed>
     */
    public static function resolved(mixed $user): array
    {
        $stored = $user->ui_settings ?? [];
        $stored = is_array($stored) ? $stored : [];

        $resolved = [];
        foreach (self::registry() as $key => $definition) {
            $resolved[$key] = array_key_exists($key, $stored)
                ? self::cast($key, $stored[$key])
                : ($definition['default'] ?? null);
        }

        return $resolved;
    }

    /**
     * The registry shaped for the frontend appearance form: key => {type, label,
     * default, options, option_labels}.
     *
     * `label` is the human field label (i18n key), falling back to the raw key so
     * the form never renders a bare machine key. `options` is the enum (or empty)
     * and `option_labels` maps each value to its human label (i18n key) — both let
     * the UI render a labelled select without re-deriving anything.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function schema(): array
    {
        $schema = [];
        foreach (self::registry() as $key => $definition) {
            $labels = $definition['labels'] ?? [];

            $schema[] = [
                'key' => $key,
                'type' => $definition['type'] ?? 'string',
                'label' => $definition['label'] ?? $key,
                'default' => $definition['default'] ?? null,
                'options' => array_values($definition['in'] ?? []),
                'option_labels' => is_array($labels) ? $labels : [],
            ];
        }

        return $schema;
    }
}
