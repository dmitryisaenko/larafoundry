<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tickets\Http\Requests\Admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Shapes an OPERATOR opening a ticket on a user's behalf (phase 4.2).
 *
 * The route is behind the `larafoundry.admin` super-admin gate, so this just
 * validates input. The target user must exist; priority/categories/labels are
 * constrained to the configured slug lists. A ticket the operator opens starts
 * `wait-customer` (the controller sets it) — status is never taken from input.
 */
class StoreTicketRequest extends FormRequest
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
        // The user picker (UserController::search) already hides the operator, so
        // the target can only be an ordinary user; harden the `exists` rule to
        // match, so a hand-crafted user_id cannot open a ticket for the operator.
        $userExists = Rule::exists($this->usersTable(), $this->usersKey());

        if ((bool) config('larafoundry.admin.exclude_super_admin_from_lists', true)) {
            $userExists->where(function ($query): void {
                $query->where('is_admin', false)->orWhereNull('is_admin');
            });
        }

        return [
            'user_id' => ['required', 'integer', $userExists],
            'title' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'min:2', 'max:5000'],
            'priority' => ['nullable', 'string', Rule::in($this->slugs('priorities'))],
            'categories' => ['nullable', 'array'],
            'categories.*' => ['string', Rule::in($this->slugs('categories'))],
            'labels' => ['nullable', 'array'],
            'labels.*' => ['string', Rule::in($this->slugs('labels'))],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function slugs(string $key): array
    {
        return (array) config("larafoundry-tickets.{$key}", []);
    }

    protected function usersTable(): string
    {
        return $this->userModel()->getTable();
    }

    protected function usersKey(): string
    {
        return $this->userModel()->getKeyName();
    }

    protected function userModel(): Model
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        return new $model;
    }
}
