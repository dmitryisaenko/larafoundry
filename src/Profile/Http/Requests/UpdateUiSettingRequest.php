<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Http\Requests;

use Dmitryisaenko\LaraFoundry\Profile\Support\UiSettings;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a single UI-preference write against the allowlist (phase 5.1).
 *
 * `key` must be a registered preference; `value` is then checked against that
 * key's declared type and optional enum in {@see withValidator()} — so an
 * unknown key or a type/enum-mismatched value is rejected before the controller
 * ever touches the column (fail-closed, recon finding #2).
 */
class UpdateUiSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', Rule::in(array_keys(UiSettings::registry()))],
            'value' => ['present'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $key = $this->input('key');
            $definition = is_string($key) ? UiSettings::definition($key) : null;

            // Unknown key: the `key` rule already failed — nothing to add.
            if ($definition === null) {
                return;
            }

            $value = $this->input('value');
            $type = $definition['type'] ?? 'string';

            if (! $this->valueMatchesType($value, $type)) {
                $validator->errors()->add('value', __('larafoundry::profile.ui_settings.invalid_value'));

                return;
            }

            $enum = $definition['in'] ?? null;
            if (is_array($enum) && $enum !== [] && ! in_array($value, $enum, true)) {
                $validator->errors()->add('value', __('larafoundry::profile.ui_settings.invalid_value'));
            }
        });
    }

    protected function valueMatchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'boolean' => is_bool($value) || in_array($value, [0, 1, '0', '1'], true),
            'integer' => is_int($value) || (is_string($value) && $value !== '' && ctype_digit(ltrim($value, '-'))),
            'float' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            default => is_string($value) || is_numeric($value) || is_bool($value),
        };
    }
}
