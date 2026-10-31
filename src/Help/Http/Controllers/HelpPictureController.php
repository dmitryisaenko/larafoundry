<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Help\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Help\Support\HelpEditor;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A help picture, served WITHOUT a session: the help is read by guests (and by a
 * mobile app's web view), and a login check would blank every picture exactly there.
 *
 * Which makes the name the only guard, and why it is a uuid the operator never
 * chooses: nothing else lives under it but pictures deliberately put into an
 * article for everyone. The name arrives off the request, so it is matched against
 * the exact shape an upload writes BEFORE the disk is touched - otherwise `..%2F`
 * would be a request for any file the disk can reach.
 *
 * Cached publicly and for good: a replaced picture is a new upload with a new name,
 * so a cache can never serve a stale one.
 */
class HelpPictureController extends Controller
{
    private const NAME = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.(jpg|png|webp|gif)$/D';

    private const TYPES = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'gif' => 'image/gif',
    ];

    public function __invoke(string $name, HelpEditor $editor): StreamedResponse
    {
        abort_unless(preg_match(self::NAME, $name) === 1, 404);

        $disk = $editor->disk();
        $path = $editor->path($name);

        abort_unless($disk->exists($path), 404);

        /** @var FilesystemAdapter $disk */
        return $disk->response($path, null, [
            'Content-Type' => self::TYPES[pathinfo($name, PATHINFO_EXTENSION)],
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
