<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Email\Support;

use HTMLPurifier;
use HTMLPurifier_Config;

/**
 * Sanitizes an email template's HTML body with an email-friendly allowlist
 * (phase 5.1), built on ezyang/htmlpurifier directly (no Laravel wrapper, so the
 * core stays unbound from any framework-major release of a wrapper package).
 *
 * DEFENCE-IN-DEPTH, not the first line of defence. The body is authored by a
 * trusted super-admin and rendered with a literal str_replace (no Blade/eval) —
 * the {@see TemplateRenderer} is what makes RCE/SSTI structurally impossible.
 * This purifier exists for the residual risk: a COMPROMISED operator account or
 * an honest paste of broken/malicious markup. So the allowlist is tuned for what
 * email actually needs (tables, inline styles, widths) rather than the paranoia
 * of hostile end-user input — over-tightening it would break legitimate email
 * layout without buying real safety. Scripts, event handlers, `javascript:` URIs
 * and frames are dropped (HTMLPurifier removes them by construction).
 *
 * The body is purified on WRITE (so the database always holds clean HTML) and on
 * PREVIEW (which renders unsaved input), keeping one server-side sanitizer as the
 * single authority — the preview iframe's `sandbox` (no allow-scripts) is a
 * second, independent barrier, not the only one.
 */
class HtmlSanitizer
{
    public function clean(string $html): string
    {
        return $this->purifier()->purify($html);
    }

    protected function purifier(): HTMLPurifier
    {
        $config = HTMLPurifier_Config::createDefault();

        // No on-disk definition cache: a reusable package cannot assume a
        // writable serializer path inside vendor/. Email bodies are short and
        // saved rarely, so building the ruleset per call is fine.
        $config->set('Cache.DefinitionImpl', null);

        // Links may open in a new tab and carry rel for safety; bare mailto/http
        // schemes only — `javascript:`/`data:` are rejected by the scheme filter.
        $config->set('Attr.AllowedFrameTargets', ['_blank']);
        $config->set('HTML.TargetBlank', false);
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);

        // Email-friendly element allowlist: text structure, lists, links, images,
        // and the table primitives mail layouts rely on. No script/iframe/form.
        $config->set('HTML.Allowed', implode(',', [
            'p', 'br', 'hr', 'span', 'div',
            'strong', 'b', 'em', 'i', 'u', 's', 'small', 'sub', 'sup',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
            'a[href|title|target|rel]',
            'img[src|alt|title|width|height|style]',
            'table[width|cellpadding|cellspacing|border|style]',
            'thead', 'tbody', 'tfoot',
            'tr[style]', 'th[align|valign|colspan|rowspan|width|style]',
            'td[align|valign|colspan|rowspan|width|style]',
            'p[style]', 'div[style]', 'span[style]', 'a[style]',
        ]));

        // Inline styles email actually uses (colours, fonts, spacing, alignment,
        // backgrounds, borders, widths). Anything outside this set is stripped.
        $config->set('CSS.AllowedProperties', [
            'color', 'background-color', 'background',
            'font', 'font-size', 'font-family', 'font-weight', 'font-style',
            'line-height', 'letter-spacing',
            'text-align', 'text-decoration', 'text-transform', 'vertical-align',
            'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
            'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
            'border', 'border-top', 'border-right', 'border-bottom', 'border-left',
            'border-color', 'border-width', 'border-style',
            'width', 'max-width', 'min-width', 'height', 'max-height',
            'white-space',
        ]);

        return new HTMLPurifier($config);
    }
}
