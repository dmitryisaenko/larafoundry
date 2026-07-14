# Files and Media Library

The media layer is one seam for storing and serving files, so avatars, company
logos and (later) host documents all travel the same disk-agnostic path instead
of hardcoding `public_path()`. Every write goes through the `MediaStorage`
contract onto a configured disk, so pointing that config at `s3` moves uploads to
the cloud with no call-site change. Image processing uses intervention/image and
the default placeholder avatar uses laravolt/avatar, rendered inline so a missing
avatar stores no file.

This is the current, accurate reference for the shipped package.

## Contents

- [Install](#install)
- [Configuration](#configuration)
- [Usage](#usage)
- [API reference](#api-reference)
- [Security notes](#security-notes)
- [Testing](#testing)

## Install

The media library ships with the core package; there is nothing extra to require
beyond the package itself (intervention/image and laravolt/avatar come in as
dependencies). Image processing needs a GD or Imagick PHP extension at runtime,
but only when an image is actually uploaded - the default initials avatar needs
neither.

The package binds the contracts and loads the private-file route automatically.
The host opts in by:

1. Defining the disks it wants in its own `config/filesystems.php`. The package
   only references disk names (`public`, `local`, or your own); it does not
   create disks.
2. Using the storage seam from its own upload flows (see Usage), or letting the
   provided model accessors (`User::avatar_url`, `Company::logo_url`) resolve
   URLs for it.

To tune limits, paths or the default avatar, publish the config:

```bash
php artisan vendor:publish --tag=larafoundry-media
```

## Configuration

All media settings live in `config/larafoundry-media.php`:

```php
return [
    'disk' => env('LARAFOUNDRY_MEDIA_DISK', 'public'),
    'private_disk' => env('LARAFOUNDRY_MEDIA_PRIVATE_DISK', 'local'),

    'paths' => [
        'avatars' => 'avatars',
        'company_logos' => 'company-logos',
    ],

    'max_upload_kb' => env('LARAFOUNDRY_MEDIA_MAX_KB', 5120),
    'image_mimes' => ['jpg', 'jpeg', 'png', 'webp'],
    'max_image_dimension' => 4000,

    'image_quality' => 90,
    'image_driver' => env('LARAFOUNDRY_MEDIA_IMAGE_DRIVER', 'gd'),
    'image_variants' => [
        'avatar' => ['method' => 'scale', 'width' => null, 'height' => 256],
        'logo' => ['method' => 'scale', 'width' => null, 'height' => 256],
    ],

    'avatar' => [
        'size' => 256,
        'shape' => 'circle',
        'chars' => 2,
    ],

    'private_url_strategy' => env('LARAFOUNDRY_MEDIA_PRIVATE_URL_STRATEGY', 'signed-route'),
    'temporary_url_minutes' => 5,
];
```

| Key | Default | What it does |
|-----|---------|--------------|
| `disk` | `public` | The disk for public assets (avatars, logos) served by URL. Point it at `s3` and uploads move to the cloud with no code change. |
| `private_disk` | `local` | The disk for files that must not be public. Reached only through a signed, expiring URL. |
| `paths.avatars` | `avatars` | Logical sub-directory under the disk for avatars. Files land in `<path>/YYYY/MM/<uuid>.<ext>`. |
| `paths.company_logos` | `company-logos` | Logical sub-directory for company logos, sharded the same way. |
| `max_upload_kb` | `5120` | Hard size ceiling (kilobytes) backing the per-request `max:` rule, so an oversized file is rejected even where a rule was forgotten. |
| `image_mimes` | `jpg, jpeg, png, webp` | Accepted extensions for image uploads (feeds the `mimes:` rule). |
| `max_image_dimension` | `4000` | Max width and height in pixels, guarding against a small-bytes / huge-pixels decompression bomb (feeds the `dimensions:` rule). |
| `image_quality` | `90` | JPEG encode quality for processed images. |
| `image_driver` | `gd` | The intervention/image driver: `gd` (needs ext-gd) or `imagick` (needs ext-imagick). |
| `image_variants` | avatar + logo | Named derived sizes the `ImageProcessor` can produce. `method` is `scale` (keeps aspect ratio, never upsizes) or `cover` (crops to exact width and height). A host adds its own sizes by publishing this file. |
| `avatar.size` / `.shape` / `.chars` | `256` / `circle` / `2` | Dimensions, shape and initial-character count of the generated placeholder avatar. |
| `private_url_strategy` | `signed-route` | How `temporaryUrl()` reaches a private file. `signed-route` mints a short-lived signed URL to the core's auth-gated route (works on any disk). `presigned` delegates to the disk's own presigned URL (set this when the private disk is S3). The choice is explicit config, not adapter auto-detection, so behaviour does not change with whatever a fake advertises. |
| `temporary_url_minutes` | `5` | Default minutes a `temporaryUrl` stays valid. |

## Usage

### Storing a file

Uploads enter the core through `StoreUploadedFileAction`, which delegates the
write to `MediaStorage` and dispatches a `FileUploaded` event. Validation is the
caller's job (a FormRequest); the action assumes the file already passed. The
storage generates a uuid filename and shards by date, so a client-supplied name
never reaches the path.

```php
use Dmitryisaenko\LaraFoundry\Media\Actions\StoreUploadedFileAction;

$stored = app(StoreUploadedFileAction::class)->execute(
    file: $request->file('avatar'),   // an UploadedFile, or raw bytes
    context: 'avatar',                 // labels the FileUploaded event
    directory: config('larafoundry-media.paths.avatars'),
    options: ['variants' => ['avatar']], // optionally also write named variants
);

$stored->path;      // 'avatars/2026/06/<uuid>.jpg' - store this on your model
$stored->disk;      // the disk it was written to
$stored->variant('avatar'); // the variant path, or the original if not generated
```

To validate first, mix `ValidatesUploadedFile` into a FormRequest and compose
`imageUploadRules()` or `fileUploadRules()` into its `rules()`; both draw the
size ceiling and accepted types from config.

Replacing or removing a file goes through `DeleteStoredFileAction`, which deletes
the path and any variant paths idempotently (a missing file is a no-op):

```php
use Dmitryisaenko\LaraFoundry\Media\Actions\DeleteStoredFileAction;

app(DeleteStoredFileAction::class)->execute(
    $model->avatar,
    array_values($stored->variants ?? []),
);
```

### Resolving an avatar

The avatar column can hold three different things, so it is never a naive
`Storage::url()`. `LaraFoundryMedia::avatarUrl()` tells them apart: empty means
generate an initials placeholder, an absolute URL (an OAuth provider's avatar) is
returned as-is, and a relative path is resolved through the configured disk. The
`User::avatar_url` accessor calls it, so a controller rarely calls it directly.

```php
use Dmitryisaenko\LaraFoundry\Media\LaraFoundryMedia;

LaraFoundryMedia::avatarUrl($user->avatar, $user->name);
// '' -> data:image/svg+xml;base64,... (initials, no stored file)
// 'https://lh3.googleusercontent.com/...' -> returned unchanged
// 'avatars/2026/06/<uuid>.jpg' -> the disk URL

LaraFoundryMedia::logoUrl($company->logo_path); // string, or null when empty
```

### Signing a private URL

Files on the private disk are not web-reachable by path. Mint a short-lived
signed URL through the storage seam after your own authorization check, then hand
it to the client:

```php
use Dmitryisaenko\LaraFoundry\Media\Contracts\MediaStorage;

$url = app(MediaStorage::class)->temporaryUrl(
    $invoice->file_path,   // a path on the private disk
    minutes: 10,           // null uses temporary_url_minutes
);
// signed-route strategy -> a signed URL to larafoundry.media.private
// presigned strategy     -> the disk's own presigned URL (S3)
```

The route (`larafoundry.media.private`) is guarded by `web`, `auth` and `signed`
middleware. It guarantees the URL is unforgeable and expiring, not who may mint
it - a host that needs per-record access ("only this order's owner may download
its invoice") layers its own gate by minting the URL only after its own check.

## API reference

### `MediaStorage` (the storage seam)

Bound as a singleton to `FileStorageManager`. Every file operation in the core
depends on this contract, never on `Storage::disk()` directly.

| Method | Signature | Purpose |
|--------|-----------|---------|
| `store` | `store(UploadedFile\|string $file, string $directory, array $options = []): StoredFile` | Write a file (upload or raw bytes) under a date-sharded path with a generated uuid name. `$options`: `disk` override, `variants` keys to also write. Image inputs are re-encoded through intervention (which doubles as a content check - a non-image throws rather than being stored). |
| `url` | `url(string $path, ?string $disk = null): string` | The public URL for a stored path. |
| `temporaryUrl` | `temporaryUrl(string $path, ?int $minutes = null, ?string $disk = null): string` | A time-limited signed URL for a private path (presigned on S3, a signed route otherwise). |
| `delete` | `delete(string $path, ?string $disk = null): bool` | Idempotent delete; a missing file is a no-op. Does not cascade to variants (the caller holds those paths). |

### `AvatarGenerator` (placeholder avatars)

Bound as a singleton to `InitialsAvatarGenerator`. Rebind it in a service
provider to use Gravatar or any other source without touching the accessors that
call it.

| Method | Signature | Purpose |
|--------|-----------|---------|
| `url` | `url(string $seed, array $options = []): string` | A renderable URL (an inline SVG `data:` URI by default) derived from the seed. `$options`: `size`, `shape`, `chars` overrides. |

`InitialsAvatarGenerator` builds the SVG via laravolt's `toSvg()` (pure string
building, no image extension), base64-encodes it into a data URI, and picks a
deterministic colour from the seed so the same name always renders identically.

### `LaraFoundryMedia` (URL resolution helper)

One place the "stored path vs external URL vs generated default" decision lives.

| Method | Returns | Purpose |
|--------|---------|---------|
| `avatarUrl($stored, $seed)` | `string` | Resolve an avatar: empty gives an initials placeholder, an absolute URL is returned as-is, a relative path resolves through `MediaStorage`. |
| `logoUrl($storedPath)` | `?string` | Resolve a company logo, or `null` when nothing is stored (logos are not auto-placeholdered). |

### `StoredFile` (the descriptor DTO)

An immutable value returned by `MediaStorage::store()`. Public readonly fields:
`path`, `disk`, `filename`, `mime`, `size`, `variants`. Methods: `variant($key)`
(the variant path or the original as a fallback) and `toArray()`. This DTO is
shaped so a future polymorphic `media` row maps one-to-one onto its fields.

### `ImageProcessor`

Thin wrapper over intervention/image, disk-agnostic (it returns encoded bytes;
the storage decides where they land). `decode($source)` reads an image once;
`encode($image, $variant = null)` clones and applies a configured variant, then
returns JPEG bytes at the configured quality. `scaleDown` keeps aspect ratio and
never upsizes; `cover` crops to exact dimensions and throws a clear
`InvalidArgumentException` if a cover variant is missing a positive width and
height.

### `ValidatesUploadedFile` (FormRequest trait)

`imageUploadRules(bool $required = false)` and `fileUploadRules(bool $required =
false)` return config-driven rule arrays (size ceiling, image mimes, dimension
cap) to compose into a request's `rules()`.

### `FileUploaded` (event)

Dispatched by `StoreUploadedFileAction` on every successful store. Carries the
`StoredFile` and a `context` string (e.g. `avatar`, `company_logo`) so a listener
can tell an avatar from a logo without inspecting the path. `getLogProperties()`
surfaces `context`, `disk`, `mime` and `size` into the activity log (phase 2.1)
when the event is in the log registry. Under
`Dmitryisaenko\LaraFoundry\Media\Events\`.

### Route

`larafoundry.media.private` (`GET larafoundry/media/private`), served by
`PrivateFileController` behind `web`, `auth` and `signed` middleware. Loaded
automatically by the service provider.

## Security notes

The media layer is built to close the findings the donor code left open:

- **A client name never steers the path.** `FileStorageManager` generates a uuid
  filename and writes into `<directory>/YYYY/MM/`, so a caller cannot send
  `../../etc/...` as a name to drive a path traversal. Images are normalised to
  `jpg`; other files keep a guessed extension, never the client-sent one.
- **Uploads are re-encoded, not trusted.** Image inputs pass through
  intervention's decode/encode, which acts as a de-facto content check - a file
  that is not really an image throws rather than being stored as one.
- **Nothing writes to `public_path()`.** Every write goes to a configured disk
  through the contract, so the core is portable to S3/local and call sites never
  hardcode a location.
- **Private files are doubly gated.** They live on a disk with no public URL and
  are reachable only through the signed, expiring route, which also requires an
  authenticated user. The signed URL embeds both the disk and the path; the
  `signed` middleware proves neither was tampered after signing, and
  `PrivateFileController` still re-validates the disk against the configured
  private disk (aborting 403 otherwise) so the route can never be pointed at the
  public disk or an arbitrary one.
- **A hard size ceiling backs validation.** `max_upload_kb` enforces a limit even
  where a per-request `max:` rule was forgotten, and `max_image_dimension` guards
  against a decompression-bomb pixel count - both defences against upload DoS.
- **Deletes cannot orphan or error.** `delete()` is idempotent (a missing or
  double-deleted path is a no-op), and the default initials avatar stores no file
  at all, so a user with no uploaded avatar can never leave an orphan.

## Testing

The media suite lives in `tests/Feature/Media/` and uses `RefreshDatabase` with
`Storage::fake()`:

- `UserAvatarTest`: the avatar column's three shapes (empty gives generated
  initials, an absolute OAuth URL is returned unchanged, a stored path resolves
  through the disk) and serialisation into the admin user resource.
- `CompanyLogoUploadTest`: the company wizard's logo step stores onto the
  configured disk with a uuid filename, exposes a resolvable `logo_url`, fires
  `FileUploaded` with the `company_logo` context, and rejects an oversized or
  non-image upload via the config-driven limits.
- `PrivateFileTest`: a signed temporary URL is minted for a private file, streams
  to an authenticated user with a valid signature, rejects an unsigned request
  (403) and a guest (redirect to login), serves a non-default private disk via
  the signed disk value, rejects a signed URL pointing at another disk (403),
  honours the configured `temporary_url_minutes` default, and 404s for a missing
  path.

Run them with Pest:

```bash
composer test
```
