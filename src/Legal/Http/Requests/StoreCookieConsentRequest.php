<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Legal\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a cookie-consent decision (phase 5.3 part B).
 *
 * Open to everyone — guests record consent before they ever sign in. The decision
 * is a closed binary set so the store can never hold an arbitrary value.
 */
class StoreCookieConsentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'decision' => ['required', 'string', Rule::in(['accept', 'necessary'])],
        ];
    }
}
