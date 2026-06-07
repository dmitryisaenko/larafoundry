<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Email\Support;

/**
 * Renders an email template string by substituting `{{variable}}` placeholders
 * with explicit data (phase 5.1).
 *
 * THIS IS THE PRIMARY SAFETY BOUNDARY of the editor. Rendering is a literal
 * `str_replace` of `{{name}}` tokens — it never compiles Blade, evaluates PHP or
 * interprets the stored string as code, so a template authored in the database
 * can never reach an expression engine (no SSTI / RCE), no matter what an
 * operator (or a compromised operator account) types. The HTML purifier on top
 * is defence-in-depth, not the first line — see the threat model.
 *
 * Substitution is a single pass (`preg_replace_callback`): the replacement is
 * never re-scanned, so an injected value that itself contains `{{...}}` is not
 * re-expanded, and surrounding whitespace (`{{ name }}`) is tolerated. Unknown
 * placeholders are emptied out by default, so a recipient never sees a raw
 * `{{token}}`; pass keepUnknown to leave them verbatim instead.
 */
class TemplateRenderer
{
    /**
     * @param  array<string, scalar|null>  $data
     */
    public function render(string $template, array $data, bool $keepUnknown = false): string
    {
        // One pass over the template: each `{{name}}` is resolved from the data
        // map and its replacement is NOT re-scanned, so an injected value that
        // itself contains `{{other}}` can never trigger a second substitution.
        // The `\s*` makes `{{ name }}` (authored with spaces) resolve too.
        return (string) preg_replace_callback(
            '/\{\{\s*(\w+)\s*\}\}/',
            function (array $matches) use ($data, $keepUnknown) {
                $key = $matches[1];

                if (array_key_exists($key, $data)) {
                    return (string) $data[$key];
                }

                // Unfilled token: drop it so delivered mail shows no raw token,
                // or keep it verbatim for the preview/inspection case.
                return $keepUnknown ? $matches[0] : '';
            },
            $template,
        );
    }

    /**
     * The variable names referenced as `{{name}}` in a template string.
     *
     * Used to validate authored templates against the registry whitelist
     * (STRICT, decision D-5.1-11): every referenced variable must be declared.
     *
     * @return list<string>
     */
    public function placeholders(string $template): array
    {
        preg_match_all('/\{\{\s*(\w+)\s*\}\}/', $template, $matches);

        return array_values(array_unique($matches[1]));
    }
}
