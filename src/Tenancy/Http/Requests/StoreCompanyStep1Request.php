<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Wizard step 1 — company basics.
 *
 * The core validates only what it owns: a name and an optional country code. The
 * set of allowed countries is a business concern (host territory), so the core
 * keeps `country` a loose string and lets the host tighten it with an `in:` rule
 * on its own subclass.
 */
class StoreCompanyStep1Request extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:10'],
            'description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
