<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Email\Http\Requests;

use Dmitryisaenko\LaraFoundry\Email\Models\EmailTemplate;
use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Dmitryisaenko\LaraFoundry\Email\Support\TemplateRenderer;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Validates the creation of a MARKETING email template (phase 2b).
 *
 * Three guards beyond the per-locale presence rules:
 *  - authorize() re-checks the `create` policy (super-admin), so the write is
 *    gated by identity, not only by the route's zone middleware;
 *  - the `code` is a fresh operator-authored slug that must be unique across BOTH
 *    the registry (transactional) codes AND the existing DB rows — it can never
 *    collide with a code-driven transactional sender (fail-closed at the request
 *    layer, mirrored by the repository);
 *  - the after-hook enforces the variable whitelist STRICTLY: every `{{token}}`
 *    used in any subject/body must be declared in the submitted `variables` list,
 *    so a marketing mail can never carry an unfillable placeholder.
 */
class StoreMarketingEmailTemplateRequest extends FormRequest
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
        $rules = [
            'code' => [
                'required',
                'string',
                'max:255',
                // Lowercase slug: a letter, then letters/digits/underscores.
                'regex:/^[a-z][a-z0-9_]*$/',
                function (string $attribute, mixed $value, callable $fail): void {
                    $code = (string) $value;

                    if ($this->repository()->isRegistered($code)) {
                        $fail(__('larafoundry::email.code_reserved'));

                        return;
                    }

                    if (EmailTemplate::query()->where('code', $code)->exists()) {
                        $fail(__('larafoundry::email.code_taken'));
                    }
                },
            ],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['boolean'],
            'variables' => ['sometimes', 'array'],
            'variables.*' => ['string', 'regex:/^[a-zA-Z_]\w*$/'],
        ];

        foreach ($this->repository()->availableLocales() as $locale) {
            $rules["subject.{$locale}"] = ['required', 'string', 'max:255'];
            $rules["body_html.{$locale}"] = ['required', 'string'];
            $rules["body_text.{$locale}"] = ['required', 'string'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // The whitelist for a marketing template is the list the operator
            // submits with it (self-contained), not any registry entry.
            $allowed = array_values((array) $this->input('variables', []));
            $renderer = app(TemplateRenderer::class);

            foreach (['subject', 'body_html', 'body_text'] as $field) {
                foreach ((array) $this->input($field, []) as $locale => $value) {
                    $unknown = array_diff($renderer->placeholders((string) $value), $allowed);

                    if ($unknown !== []) {
                        $validator->errors()->add(
                            "{$field}.{$locale}",
                            __('larafoundry::email.unknown_variables', ['vars' => implode(', ', $unknown)]),
                        );
                    }
                }
            }
        });
    }

    protected function repository(): EmailTemplateRepository
    {
        return app(EmailTemplateRepository::class);
    }
}
