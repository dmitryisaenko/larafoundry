<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Media\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves a file from the private disk behind a signed, expiring URL (phase 2.4).
 *
 * Private files (the seam for host order/invoice documents in phase 7) are NOT
 * web-reachable by path — they live on a disk with no public URL. This is the
 * one door to them, and it is doubly gated: the `signed` middleware proves the
 * URL was minted by the app and has not expired (recon finding #7, no permanent
 * raw-path access), and the route also requires an authenticated user.
 *
 * The signed URL embeds disk + path. It can only address the configured private
 * disk (a tampered `disk` is rejected) and the path is used verbatim against
 * that disk's adapter, which contains traversal to the disk root — combined with
 * the uuid filenames the storage generates, a crafted path cannot escape it.
 */
class PrivateFileController extends Controller
{
    public function __invoke(Request $request): StreamedResponse
    {
        $path = (string) $request->query('path', '');

        // The disk is carried in the signed query (so a non-default private disk
        // is served correctly), but is still validated against the configured
        // private disk: the signed middleware guarantees the value is untampered,
        // and this check guarantees the route can never be pointed at the public
        // disk or an arbitrary one even if a future caller signs a different disk.
        $disk = (string) $request->query('disk', config('larafoundry-media.private_disk', 'local'));

        abort_unless($disk === (string) config('larafoundry-media.private_disk', 'local'), 403);

        $fs = Storage::disk($disk);

        abort_unless($path !== '' && $fs->exists($path), 404);

        return $fs->response($path);
    }
}
