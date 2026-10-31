<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Help\Models;

use Dmitryisaenko\LaraFoundry\Help\Support\HelpEditor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One article inside a section: an accordion on the reader's page.
 *
 * `body_translations` holds HTML that was cleaned by the HtmlSanitizer on write, and
 * nothing downstream cleans it again - so nothing may write these columns except
 * {@see HelpEditor} and the guide loader, both of which clean.
 *
 * `mobile_body_translations` is the same article written for a phone; null (or
 * empty) where there is none, and the phone then shows the desktop text.
 */
class HelpArticle extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'help_section_id',
        'heading_translations',
        'body_translations',
        'mobile_body_translations',
        'position',
        'is_published',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'heading_translations' => 'array',
            'body_translations' => 'array',
            'mobile_body_translations' => 'array',
            'position' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    public function getTable(): string
    {
        return 'larafoundry_help_articles';
    }

    /**
     * @return BelongsTo<HelpSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(HelpSection::class, 'help_section_id');
    }
}
