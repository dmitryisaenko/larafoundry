<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Legal\Http\Requests;

use Dmitryisaenko\LaraFoundry\Legal\Models\LegalPage;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Validates a super-admin's live preview of unsaved legal-page HTML (phase 5.3).
 *
 * authorize() re-checks the legal-page policy: the preview echoes the operator's
 * own input back through the server-side sanitizer, so it must be just as
 * super-admin-only as editing.
 */
class PreviewLegalPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && Gate::forUser($this->user())->allows('update', LegalPage::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'body_html' => ['nullable', 'string'],
        ];
    }
}
