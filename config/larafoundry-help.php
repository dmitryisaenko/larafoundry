<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| LaraFoundry help (v0.48.0)
|--------------------------------------------------------------------------
| The in-app help: sections (a tab each) holding ordered articles (an
| accordion each), every text in every available locale, edited by the
| super-admin and read by everyone (guests included). Pictures are records,
| put into a text as `[[img:ref]]` and resolved when the help is read, so a
| retaken screenshot is one upload rather than an edit of every translation.
|
| The tables are created always. A host that draws the help on screens of its
| own switches the core's screens off below and reads the same data through
| `Help\Support\HelpBook` (reader) and `Help\Support\HelpEditor` (writer).
*/

return [

    /*
    | The language the operator writes in. Translations are made FROM it, and
    | a reader whose language has no text yet is shown this one (with a note
    | that it is a substitute) before the app's default locale. Null = the
    | app's default locale (`larafoundry.locale.default`).
    */
    'source_locale' => env('LARAFOUNDRY_HELP_SOURCE_LOCALE'),

    /*
    | The public help page (`/help`, guests included). Off - the route is not
    | registered at all, so the path stays free for a host's own help page.
    */
    'reader' => [
        'enabled' => env('LARAFOUNDRY_HELP_READER_ENABLED', true),
        'path' => 'help',
    ],

    /*
    | The editor in the core's admin section (`/admin/help-articles`) and its
    | menu entry. Off - neither is registered; a host with a console of its
    | own edits through `HelpEditor`.
    */
    'admin' => [
        'enabled' => env('LARAFOUNDRY_HELP_ADMIN_ENABLED', true),
    ],

    /*
    | Where uploaded pictures are kept. They are served by the core's own
    | route (`/help/pictures/{name}`, open to guests - the help is), never by
    | the disk's URL, so a private disk works. `default_width` is the desktop
    | ceiling of a picture that sets none (a phone always draws it full width).
    */
    'pictures' => [
        'disk' => env('LARAFOUNDRY_HELP_DISK', 'local'),
        'directory' => 'larafoundry-help',
        'default_width' => 700,
        'max_upload_kb' => 4096,
    ],

    /*
    | Where `larafoundry:help-load-guide {section}` looks for a section's
    | article files by default: `<guide_path>/<section>/NN-some-words.php`.
    | Null = `resource_path('help')`.
    */
    'guide_path' => null,

];
