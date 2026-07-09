<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Email\Http\Requests;

use Dmitryisaenko\LaraFoundry\Email\Models\EmailTemplate;
use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Validates a DRAFT preview request from the marketing create form (phase 2b).
 *
 * A being-authored marketing template is not persisted yet, so it has no code to
 * resolve — this preview renders the submitted subject/body directly, deriving
 * sample data from the submitted `variables` list, and purifies the html
 * server-side. Same super-admin authority as creating a template.
 */
class PreviewDraftEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && Gate::forUser($this->user())->allows('create', EmailTemplate::class);
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
            'variables' => ['sometimes', 'array'],
            'variables.*' => ['string'],
        ];
    }
}
