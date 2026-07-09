<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Email\Models;

use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * An email template row (phase 5.1, extended to two layers in phase 2b).
 *
 * A row belongs to one of two layers, told apart by `type`:
 *  - transactional — a super-admin's OVERRIDE of a registry code (welcome_email,
 *    password_reset, …). Thin: the shipped defaults, the variable whitelist and
 *    all rendering live in the config registry read by {@see EmailTemplateRepository},
 *    never on the model; `code` ties the row to a Notification that sends it. Such
 *    a row exists only once a template is edited (fail-closed, never created or
 *    deleted from the panel).
 *  - marketing — a SELF-CONTAINED operator-authored template. It carries its own
 *    `name` (label) and its own `variables` whitelist and needs no registry entry;
 *    it is fully create/duplicate/delete-able.
 *
 * The `*_translations` columns are JSON maps keyed by locale ({"en": "...",
 * "uk": "..."}). Global — no company_id (the editor is super-admin-only).
 */
class EmailTemplate extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'type',
        'name',
        'variables',
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
            'variables' => 'array',
            'subject_translations' => 'array',
            'body_html_translations' => 'array',
            'body_text_translations' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Self-contained operator-authored templates (full CRUD).
     *
     * @param  Builder<EmailTemplate>  $query
     */
    public function scopeMarketing(Builder $query): void
    {
        $query->where('type', 'marketing');
    }

    /**
     * Overrides of registry codes (edit-only, fail-closed).
     *
     * @param  Builder<EmailTemplate>  $query
     */
    public function scopeTransactional(Builder $query): void
    {
        $query->where('type', 'transactional');
    }

    /**
     * A transactional row mirrors a registry code and can never be deleted or
     * renamed from the panel; a marketing row is self-contained.
     */
    public function isTransactional(): bool
    {
        return $this->type !== 'marketing';
    }

    public function getTable(): string
    {
        return 'larafoundry_email_templates';
    }
}
