<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Legal\Http\Requests;

use Dmitryisaenko\LaraFoundry\Legal\Models\LegalPage;
use Dmitryisaenko\LaraFoundry\Legal\Support\LegalPageRepository;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

/**
 * Validates a super-admin's edit of a legal page (phase 5.3).
 *
 * authorize() re-checks the legal-page policy (super-admin), so the write is gated
 * by identity, not only by the route's zone middleware (mirrors D-5.1-3). Unlike
 * the email-template editor there is NO variable whitelist: legal pages are static
 * HTML with no `{{token}}` substitution, which removes that surface entirely. The
 * html body is purified by the repository on save; here we only require a title +
 * body per available locale and validate the publish/bump flags.
 */
class UpdateLegalPageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && Gate::forUser($this->user())->allows('update', LegalPage::class);
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'is_published' => ['boolean'],
            'bump_version' => ['boolean'],
        ];

        foreach ($this->repository()->availableLocales() as $locale) {
            $rules["title.{$locale}"] = ['required', 'string', 'max:255'];
            $rules["body_html.{$locale}"] = ['required', 'string'];
        }

        return $rules;
    }

    protected function repository(): LegalPageRepository
    {
        return app(LegalPageRepository::class);
    }
}
