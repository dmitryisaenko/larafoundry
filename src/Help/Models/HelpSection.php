<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Help\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One section of the help: a tab on the reader's page, a heading in the editor.
 *
 * `key` is the section's address (`/help?section=billing`), kept apart from the
 * title so a renamed tab keeps its links. Every text column is a JSON map keyed by
 * locale; read it through `HelpText`, which falls back rather than handing the
 * reader an empty box.
 */
class HelpSection extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'key',
        'title_translations',
        'blurb_translations',
        'position',
        'is_published',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'title_translations' => 'array',
            'blurb_translations' => 'array',
            'position' => 'integer',
            'is_published' => 'boolean',
        ];
    }

    public function getTable(): string
    {
        return 'larafoundry_help_sections';
    }

    /**
     * @return HasMany<HelpArticle, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(HelpArticle::class, 'help_section_id')->orderBy('position');
    }
}
