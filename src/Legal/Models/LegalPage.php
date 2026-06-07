<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Legal\Models;

use Dmitryisaenko\LaraFoundry\Email\Models\EmailTemplate;
use Dmitryisaenko\LaraFoundry\Legal\Support\LegalPageRepository;
use Illuminate\Database\Eloquent\Model;

/**
 * A super-admin's published legal page — Terms, Privacy, Cookie policy (phase 5.3).
 *
 * Deliberately thin, mirroring {@see EmailTemplate}:
 * a row exists ONLY once an operator has edited a page; the shipped placeholder
 * defaults and the list of editable slugs live in the config registry read by
 * {@see LegalPageRepository}, never on the model. The `*_translations` columns are
 * JSON maps keyed by locale ({"en": "...", "uk": "..."}); `slug` ties the row to a
 * registry entry. Global — no company_id (the editor is super-admin-only).
 *
 * `version` is the integer a super-admin bumps when a change is material enough to
 * require re-acceptance; it feeds the terms re-accept gate (phase 5.3 part B).
 * `is_published` is what makes a page publicly visible and enforceable — a page is
 * never shown from its placeholder default until an operator publishes it.
 */
class LegalPage extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'slug',
        'title_translations',
        'body_html_translations',
        'version',
        'is_published',
        'published_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'title_translations' => 'array',
            'body_html_translations' => 'array',
            'version' => 'integer',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        return 'larafoundry_legal_pages';
    }
}
