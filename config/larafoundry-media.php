<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LaraFoundry — file / media library (phase 2.4)
|--------------------------------------------------------------------------
| Everything the core writes goes through the MediaStorage contract onto a
| CONFIGURED disk — never `public_path()` directly (the donor did, recon DROP,
| not portable to S3). Change `disk` to `s3` and avatars/logos move to the
| cloud with no code change.
|
| Image processing (resize/variants) uses intervention/image; the default
| placeholder avatar uses laravolt/avatar. Both require a GD or Imagick PHP
| extension at runtime (`ext-gd` is the common choice on shared hosting).
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Disks
    |--------------------------------------------------------------------------
    | `disk` holds public assets (avatars, company logos) — files meant to be
    | served by URL. `private_disk` holds files that must NOT be public; they
    | are reached only through a time-limited signed URL (temporaryUrl) and an
    | authorization check (recon finding #7). The host defines the actual disks
    | in its own config/filesystems.php; these names just point at them.
    */
    'disk' => env('LARAFOUNDRY_MEDIA_DISK', 'public'),
    'private_disk' => env('LARAFOUNDRY_MEDIA_PRIVATE_DISK', 'local'),

    /*
    |--------------------------------------------------------------------------
    | Logical paths
    |--------------------------------------------------------------------------
    | Sub-directories (under the disk root) the core writes into. Files land in
    | `<path>/YYYY/MM/<uuid>.<ext>`: the date shards keep directories from
    | growing unbounded and the uuid filename closes path traversal (recon
    | finding #6) — a client-supplied name is never used in the path.
    */
    'paths' => [
        'avatars' => 'avatars',
        'company_logos' => 'company-logos',
    ],

    /*
    |--------------------------------------------------------------------------
    | Upload limits
    |--------------------------------------------------------------------------
    | A hard ceiling enforced in addition to per-request validation, so a host
    | request that forgets a `max:` rule still cannot accept an oversized file
    | (recon finding #4, upload DoS). `image_mimes` is the accepted set for
    | image uploads. Dimensions guard against decompression-bomb pixel counts.
    */
    'max_upload_kb' => env('LARAFOUNDRY_MEDIA_MAX_KB', 5120),
    'image_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
    'max_image_dimension' => 4000,

    /*
    |--------------------------------------------------------------------------
    | Image processing
    |--------------------------------------------------------------------------
    | `quality` is the JPEG/WebP encode quality. `variants` are named derived
    | sizes the ImageProcessor can produce; the core ships avatar/logo sizes
    | (universal), and the host adds domain sizes (product thumbnails, …) by
    | publishing this file. `method` is the intervention call: `scale` keeps
    | aspect ratio (never upsizes); `cover` crops to exact width×height.
    */
    'image_quality' => 90,

    /*
    | The intervention/image driver used to process uploads. 'gd' (default) needs
    | ext-gd; 'imagick' needs ext-imagick. Only relevant when images are actually
    | uploaded — the default initials avatar needs no extension at all.
    */
    'image_driver' => env('LARAFOUNDRY_MEDIA_IMAGE_DRIVER', 'gd'),

    'image_variants' => [
        'avatar' => ['method' => 'scale', 'width' => null, 'height' => 256],
        'logo' => ['method' => 'scale', 'width' => null, 'height' => 256],
    ],

    /*
    |--------------------------------------------------------------------------
    | Default avatar
    |--------------------------------------------------------------------------
    | When a user/company has no uploaded image, the AvatarGenerator renders a
    | placeholder. The shipped generator draws the initials inline (a data URI),
    | so a missing avatar costs no stored file and cannot orphan; `size`,
    | `shape` and `chars` tune that drawing. To use Gravatar (or any other
    | source) instead, rebind the AvatarGenerator contract in a service provider
    | — the core ships initials only.
    */
    'avatar' => [
        'size' => 256,
        'shape' => 'circle',
        'chars' => 2,
    ],

    /*
    |--------------------------------------------------------------------------
    | Private-file URL strategy
    |--------------------------------------------------------------------------
    | How temporaryUrl() reaches a file on the private disk:
    |   'signed-route' (default) — a short-lived signed URL to the core's
    |       auth-gated private-file route. Works on any disk, including local.
    |   'presigned' — delegate to the disk's own presigned URL (S3/cloud). Set
    |       this when the private disk is S3 so the file streams from the bucket
    |       directly rather than through the app.
    | The default is explicit (not auto-detected) so behaviour does not change
    | with whatever a fake/adapter happens to advertise.
    */
    'private_url_strategy' => env('LARAFOUNDRY_MEDIA_PRIVATE_URL_STRATEGY', 'signed-route'),

    /*
    |--------------------------------------------------------------------------
    | Signed-route lifetime
    |--------------------------------------------------------------------------
    | Default minutes a temporaryUrl stays valid for private files.
    */
    'temporary_url_minutes' => 5,

];
