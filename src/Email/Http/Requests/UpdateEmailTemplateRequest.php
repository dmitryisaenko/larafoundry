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
 * Validates a super-admin's edit of an email template (phase 5.1).
 *
 * Two guards beyond the per-locale presence rules:
 *  - authorize() re-checks the email-template policy (super-admin), so the write
 *    is gated by identity, not only by the route's zone middleware (D-5.1-3);
 *  - the after-hook enforces the variable whitelist STRICTLY (D-5.1-11): every
 *    `{{token}}` referenced in any subject/body must be declared in the RIGHT
 *    whitelist for the layer — a transactional code reads the registry `variables`,
 *    a marketing row uses the `variables` submitted with the edit (self-contained).
 *    Otherwise the save is rejected (422), so a delivered mail can never carry an
 *    unfillable placeholder.
 *
 * Editing a marketing row also accepts `name` + `variables`; those keys are simply
 * ignored by the controller for a transactional row.
 */
class UpdateEmailTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && Gate::forUser($this->user())->allows('update', EmailTemplate::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'is_active' => ['boolean'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
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
            $code = (string) $this->route('code');

            $isRegistered = $this->repository()->isRegistered($code);
            $isMarketing = ! $isRegistered && $this->repository()->find($code) !== null;

            if (! $isRegistered && ! $isMarketing) {
                return; // The controller resolves the code; an unknown one 404s there.
            }

            // Transactional → the registry whitelist; marketing → the whitelist the
            // operator submits with this edit (they may add/remove a variable and
            // use it in the same save).
            $allowed = $isRegistered
                ? $this->repository()->variablesFor($code)
                : array_values((array) $this->input('variables', []));

            $renderer = app(TemplateRenderer::class);

            foreach (['subject', 'body_html', 'body_text'] as $field) {
                $values = (array) $this->input($field, []);

                foreach ($values as $locale => $value) {
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
