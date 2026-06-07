<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Email\Http\Requests;

use Dmitryisaenko\LaraFoundry\Email\Models\EmailTemplate;
use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Validates a send-test-email request from the editor (phase 5.1).
 *
 * Sends the SAVED (or default) template, rendered with sample data, to an
 * address the operator types. Super-admin only; the route is rate-limited
 * (config `larafoundry-email.test_email.throttle`) so the editor cannot be used
 * to spray mail at arbitrary recipients — a gap in the donor.
 */
class SendTestEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && Gate::forUser($this->user())->allows('test', EmailTemplate::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'locale' => ['required', 'string', Rule::in(app(EmailTemplateRepository::class)->availableLocales())],
        ];
    }
}
