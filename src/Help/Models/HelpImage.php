<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Help\Models;

use Dmitryisaenko\LaraFoundry\Help\Contracts\HelpPictureOptions;
use Illuminate\Database\Eloquent\Model;

/**
 * A picture a help text refers to as `[[img:ref]]`.
 *
 * The reference survives the file: a retaken screenshot is this row pointing at a
 * new file, and no translation has to be touched. `file` is a uuid name the
 * operator never chooses (the pictures are served without a session).
 * `max_width` is the picture's own desktop ceiling; null - the configured default.
 */
class HelpImage extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'ref',
        'file',
        'caption_translations',
        'max_width',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'caption_translations' => 'array',
            'max_width' => 'integer',
        ];
    }

    public function getTable(): string
    {
        return 'larafoundry_help_images';
    }

    /** Where a reader's browser fetches the file - through the host-replaceable options. */
    public function url(): string
    {
        return app(HelpPictureOptions::class)->url($this->file);
    }

    /** What a text writes to show this picture. */
    public function marker(): string
    {
        return '[[img:'.$this->ref.']]';
    }
}
