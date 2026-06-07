<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Confirms an account deletion — the destructive, grace-period-erasure trigger
 * (phase 5.1, OAuth-only path added in phase 5.3).
 *
 * Re-auth on a destructive action so a hijacked session cannot delete the
 * account on its own. Two paths by what the account actually has:
 *
 *  - password account: the current password (the strong factor);
 *  - OAuth-only account (no local password, decision D-5.3-11): retype the exact
 *    email address AND tick an explicit confirmation box. This is weaker than a
 *    password (a hijacked session knows the email), but it is the only factor an
 *    OAuth-only account holds; the retype + checkbox prove deliberate intent.
 *    This closes the phase-5.1 gap where OAuth-only users could not self-delete.
 */
class DeleteAccountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        if ($this->isOauthOnly()) {
            return [
                'email' => ['required', 'string', $this->matchesCurrentEmail()],
                'confirm_deletion' => ['accepted'],
            ];
        }

        return [
            'current_password' => ['required', 'string', 'current_password:web'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'current_password.current_password' => __('larafoundry::auth.password.current_invalid'),
            'confirm_deletion.accepted' => __('larafoundry::profile.account.confirm_required'),
        ];
    }

    /**
     * Whether the signed-in user authenticates only through OAuth (no password).
     */
    protected function isOauthOnly(): bool
    {
        $user = $this->user();

        return $user !== null
            && method_exists($user, 'isOauthOnly')
            && $user->isOauthOnly();
    }

    /**
     * A rule that the submitted value equals the user's own email (case-folded).
     */
    protected function matchesCurrentEmail(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $current = (string) ($this->user()?->getAttribute('email') ?? '');

            if ($current === '' || mb_strtolower(trim((string) $value)) !== mb_strtolower($current)) {
                $fail(__('larafoundry::profile.account.confirm_email'));
            }
        };
    }
}
