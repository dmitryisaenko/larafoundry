<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Email\Http\Requests;

use Dmitryisaenko\LaraFoundry\Email\Models\EmailTemplate;
use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Validates a live preview request from the editor (phase 5.1).
 *
 * The preview renders UNSAVED editor input for one locale with sample data and
 * purifies the html server-side, so the operator sees exactly what would be
 * delivered. Super-admin only, like the rest of the editor.
 */
class PreviewEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && Gate::forUser($this->user())->allows('view', EmailTemplate::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', Rule::in(app(EmailTemplateRepository::class)->availableLocales())],
            'subject' => ['nullable', 'string'],
            'body_html' => ['nullable', 'string'],
            'body_text' => ['nullable', 'string'],
        ];
    }
}
