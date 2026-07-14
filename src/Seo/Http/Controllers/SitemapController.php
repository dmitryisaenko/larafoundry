<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Seo\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Seo\Support\SitemapBuilder;
use Dmitryisaenko\LaraFoundry\Seo\Support\Url;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

/**
 * The public /sitemap.xml (phase 5.2).
 *
 * Renders a valid sitemaps.org urlset from the {@see SitemapBuilder}'s merged
 * entries, with xhtml:link hreflang alternates. 404s when the sitemap is
 * disabled. The rendered XML is cached for the configured TTL, so a crawler hit
 * is one cache read, not a full provider walk.
 *
 * SECURITY: every URL is scheme-guarded ({@see Url::isHttp()}) before it is
 * written, and every value is XML-escaped, so a stray value can neither inject
 * markup into the document nor smuggle a non-http scheme into a <loc>.
 */
class SitemapController extends Controller
{
    public function index(SitemapBuilder $builder): Response
    {
        abort_unless((bool) config('larafoundry-seo.sitemap.enabled', true), 404);

        $ttl = (int) config('larafoundry-seo.sitemap.cache_ttl', 3600);

        $xml = Cache::remember('larafoundry.sitemap', $ttl, fn () => $this->render($builder->build()));

        return response($xml, 200)->header('Content-Type', 'application/xml');
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     */
    protected function render(array $entries): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">'."\n";

        foreach ($entries as $entry) {
            $loc = $entry['loc'] ?? null;

            if (! Url::isHttp(is_string($loc) ? $loc : null)) {
                continue;
            }

            $xml .= "  <url>\n";
            $xml .= '    <loc>'.$this->esc($loc)."</loc>\n";

            if (! empty($entry['lastmod'])) {
                $xml .= '    <lastmod>'.$this->esc((string) $entry['lastmod'])."</lastmod>\n";
            }

            if (! empty($entry['changefreq'])) {
                $xml .= '    <changefreq>'.$this->esc((string) $entry['changefreq'])."</changefreq>\n";
            }

            if (isset($entry['priority']) && $entry['priority'] !== null) {
                $xml .= '    <priority>'.number_format((float) $entry['priority'], 1)."</priority>\n";
            }

            foreach (($entry['alternates'] ?? []) as $locale => $url) {
                if (Url::isHttp(is_string($url) ? $url : null)) {
                    $xml .= '    <xhtml:link rel="alternate" hreflang="'.$this->esc((string) $locale).'" href="'.$this->esc($url).'"/>'."\n";
                }
            }

            $xml .= "  </url>\n";
        }

        $xml .= '</urlset>'."\n";

        return $xml;
    }

    /**
     * XML-escape a value for text content and attributes (ENT_XML1 | ENT_QUOTES).
     */
    protected function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
