<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Email\Models;

use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Illuminate\Database\Eloquent\Model;

/**
 * A super-admin's override of an email template (phase 5.1).
 *
 * Deliberately thin: a row exists ONLY when an operator has edited a template;
 * the shipped defaults, the variable whitelist and all rendering live in the
 * config registry read by {@see EmailTemplateRepository}, never on the model.
 * The `*_translations` columns are JSON maps keyed by locale ({"en": "...",
 * "uk": "..."}); `code` ties the row to a registry entry and to the Notification
 * that sends it. Global — no company_id (the editor is super-admin-only).
 */
class EmailTemplate extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'subject_translations',
        'body_html_translations',
        'body_text_translations',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_translations' => 'array',
            'body_html_translations' => 'array',
            'body_text_translations' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function getTable(): string
    {
        return 'larafoundry_email_templates';
    }
}
