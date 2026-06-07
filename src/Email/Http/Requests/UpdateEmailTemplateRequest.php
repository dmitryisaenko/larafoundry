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
 *    `{{token}}` referenced in any subject/body must be declared in the
 *    template's registry `variables`, otherwise the save is rejected (422). That
 *    guarantees a delivered mail can never carry an unfillable placeholder and
 *    kills the donor's three-out-of-sync variable lists at the source.
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
        $rules = ['is_active' => ['boolean']];

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

            if (! $this->repository()->isRegistered($code)) {
                return; // The controller resolves the code; an unknown one 404s there.
            }

            $allowed = $this->repository()->variablesFor($code);
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
