<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tenancy\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Wizard step 2 — company details.
 *
 * In the core this is deliberately minimal: the logo upload. Business detail
 * fields (financial/contact details by country, activity type, …) are host
 * columns, so the host extends this request to validate them. Keeping the core
 * step small means the wizard works out of the box and the host adds depth.
 */
class StoreCompanyStep2Request extends FormRequest
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
        // Logo only. Description is collected in step 1 (and persisted there);
        // it must NOT be accepted here, or an empty step-2 submission would
        // overwrite the step-1 description with a blank string.
        return [
            'logo' => ['nullable', 'image', 'max:2048'],
        ];
    }
}
