<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Profile\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in user's own profile, shaped for the profile hub (phase 5.1).
 *
 * Distinct from AdminUserResource (the operator's view of any user): this is
 * the self view, so it carries `avatar_url`, the verification flag and the
 * "can I change my email / set a password" hints the hub's tabs branch on — and
 * never the privilege/blocking columns an operator screen shows.
 *
 * @property-read Model $resource
 */
class ProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'id' => $user->getKey(),
            'name' => $user->getAttribute('name'),
            'lastname' => $user->getAttribute('lastname'),
            'middlename' => $user->getAttribute('middlename'),
            'email' => $user->getAttribute('email'),
            'phone' => $user->getAttribute('phone'),
            'country' => $user->getAttribute('country'),
            'sex' => $user->getAttribute('sex'),
            'birth_date' => optional($user->getAttribute('birth_date'))->format('Y-m-d'),
            'locale' => $user->getAttribute('locale'),
            'avatar_url' => $user->getAttribute('avatar_url'),
            'email_verified' => $user->getAttribute('email_verified_at') !== null,
            // OAuth-only users have no local password, so the hub hides the
            // password tab and the email/delete flows that need current_password.
            'has_password' => $user->getAttribute('password') !== null,
        ];
    }
}
