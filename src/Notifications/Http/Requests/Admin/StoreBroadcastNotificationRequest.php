<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Notifications\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shapes a super-admin broadcast create/update (phase 4.1).
 *
 * The route already sits behind `larafoundry.admin` (super-admin), so this just
 * authorizes an authenticated request and validates input. English is the
 * required base locale (the inbox falls back to it); other available locales are
 * optional. `status` is never accepted from input — it is a transition driven by
 * the send endpoint. `visible_until` must not precede `visible_from`
 * (security finding #8). Body length is capped; the frontend renders it as text
 * (no HTML execution), so no markup is interpreted (finding #1).
 */
class StoreBroadcastNotificationRequest extends FormRequest
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
        $types = array_keys((array) config('larafoundry-notifications.types', []));
        $locales = (array) config('larafoundry.locale.available', ['en']);

        $rules = [
            'code' => ['required', 'string', Rule::in($types)],

            'title_translations' => ['required', 'array'],
            'title_translations.en' => ['required', 'string', 'max:255'],
            'body_translations' => ['nullable', 'array'],
            'body_translations.en' => ['nullable', 'string', 'max:10000'],

            'recipients' => ['nullable', 'array'],
            'recipients.emailVerified' => ['nullable', 'in:verified,unverified'],
            'recipients.recentActivity' => ['nullable', 'in:1,24,168'],
            'recipients.role' => ['nullable', 'string', 'max:255'],

            'send_mail' => ['nullable', 'boolean'],

            'visible_from' => ['nullable', 'date'],
            'visible_until' => ['nullable', 'date', 'after_or_equal:visible_from'],
        ];

        foreach ($locales as $locale) {
            if ($locale === 'en') {
                continue;
            }

            $rules["title_translations.{$locale}"] = ['nullable', 'string', 'max:255'];
            $rules["body_translations.{$locale}"] = ['nullable', 'string', 'max:10000'];
        }

        return $rules;
    }
}
