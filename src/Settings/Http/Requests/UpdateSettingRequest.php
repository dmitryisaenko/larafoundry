<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a single setting write (phase 5.1).
 *
 * `key` must be a registered setting; the per-endpoint controller then enforces
 * that the key actually belongs to that endpoint's scope and is form-editable,
 * and the repository validates the value against the key's declared rule. So the
 * value never reaches the store without passing the registry's own validation
 * (fail-closed).
 */
class UpdateSettingRequest extends FormRequest
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
            'key' => ['required', 'string', Rule::in(array_keys((array) config('larafoundry.settings', [])))],
            'value' => ['present'],
        ];
    }
}
