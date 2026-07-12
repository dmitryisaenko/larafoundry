<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Email\Console\Commands;

use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;

/**
 * Renders every registered email template across every available locale so an
 * operator can eyeball the real output — the human-readable half of the
 * "test the templates and languages" story (the machine half is the Pest
 * matrix in EmailTemplateMatrixTest).
 *
 * Three output modes, all reading the same {@see EmailTemplateRepository::render()}
 * path production sends through, filled with the repository's own sampleData():
 *
 *   (default)  a table in the terminal — code × locale, subject, and a
 *              health verdict (OK / empty / unfilled placeholder / stray {{ }}).
 *   --html     write each render to storage/app/larafoundry-mail-preview/<code>.<locale>.html
 *              (open in a browser to check the actual layout).
 *   --log      push each render through the mail pipeline so it lands wherever
 *              MAIL_MAILER points (log driver -> Telescope > Mail locally).
 *
 * --code and --locale narrow the run. Read-only against the DB: it renders the
 * registry defaults merged with any stored overrides, sends nothing to real
 * recipients (the --log recipient is a fixed preview@ address).
 */
class PreviewEmailTemplatesCommand extends Command
{
    protected $signature = 'larafoundry:mail-preview
        {--code=* : Limit to these template codes (default: all registered)}
        {--locale=* : Limit to these locales (default: all available)}
        {--html : Write each render to an HTML file under storage/app/larafoundry-mail-preview}
        {--log : Send each render through the mail pipeline (log driver -> Telescope)}';

    protected $description = 'Preview every email template in every locale (terminal table, HTML files, or mail log)';

    public function handle(EmailTemplateRepository $templates): int
    {
        $codes = $this->resolveCodes($templates);
        $locales = $this->resolveLocales($templates);

        if ($codes === []) {
            $this->warn('No matching email templates.');

            return self::SUCCESS;
        }

        $toHtml = (bool) $this->option('html');
        $toLog = (bool) $this->option('log');
        $dir = storage_path('app/larafoundry-mail-preview');

        if ($toHtml) {
            File::ensureDirectoryExists($dir);
        }

        $rows = [];
        $problems = 0;

        foreach ($codes as $code) {
            $data = $templates->sampleData($code);

            $variables = $templates->variablesFor($code);

            foreach ($locales as $locale) {
                $rendered = $templates->render($code, $locale, $data);

                if ($rendered === null) {
                    $rows[] = [$code, $locale, '—', '<comment>SWITCHED OFF</comment>'];

                    continue;
                }

                $verdict = $this->verdict($rendered, $variables);
                if ($verdict !== 'OK') {
                    $problems++;
                }

                $rows[] = [
                    $code,
                    $locale,
                    $this->truncate($rendered['subject']),
                    $verdict === 'OK' ? '<info>OK</info>' : "<error>{$verdict}</error>",
                ];

                if ($toHtml) {
                    $path = $dir.DIRECTORY_SEPARATOR."{$code}.{$locale}.html";
                    File::put($path, $this->wrapHtml($code, $locale, $rendered));
                }

                if ($toLog) {
                    Mail::html($rendered['html'], function ($message) use ($rendered): void {
                        $message->to('preview@larafoundry.local')->subject($rendered['subject']);
                    });
                }
            }
        }

        $this->table(['Code', 'Locale', 'Subject', 'Health'], $rows);

        if ($toHtml) {
            $this->info("HTML previews written to: {$dir}");
        }
        if ($toLog) {
            $this->info('Rendered mails sent through the mail pipeline (check your mail log / Telescope).');
        }

        if ($problems > 0) {
            $this->warn("{$problems} render(s) look unhealthy (empty, unfilled placeholder, or stray {{ }}).");

            return self::FAILURE;
        }

        $this->info('All previewed templates rendered cleanly.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    protected function resolveCodes(EmailTemplateRepository $templates): array
    {
        $requested = array_filter((array) $this->option('code'));
        $available = array_keys($templates->registry());

        if ($requested === []) {
            return array_values($available);
        }

        return array_values(array_intersect($available, $requested));
    }

    /**
     * @return list<string>
     */
    protected function resolveLocales(EmailTemplateRepository $templates): array
    {
        $requested = array_filter((array) $this->option('locale'));
        $available = $templates->availableLocales();

        if ($requested === []) {
            return $available;
        }

        return array_values(array_intersect($available, $requested));
    }

    /**
     * A quick health read of a render: the subject must be non-empty, and neither
     * subject nor body may still carry a `{{token}}` the renderer failed to
     * substitute or an unfilled `[variable]` sample.
     *
     * The unfilled check is scoped to the template's OWN whitelisted variables —
     * sampleData() only ever emits `[<name>]` for a whitelisted variable it has no
     * known sample for, so a `[DEV]` inside a real value (e.g. app.name) is not a
     * false positive.
     *
     * @param  array{subject: string, html: string, text: string}  $rendered
     * @param  list<string>  $variables
     */
    protected function verdict(array $rendered, array $variables): string
    {
        $subject = $rendered['subject'];
        $blob = $subject."\n".$rendered['html']."\n".$rendered['text'];

        if (trim($subject) === '' || trim($rendered['html']) === '') {
            return 'EMPTY';
        }

        if (preg_match('/\{\{.*?\}\}/', $blob) === 1) {
            return 'STRAY {{ }}';
        }

        foreach ($variables as $variable) {
            if (str_contains($blob, '['.$variable.']')) {
                return 'UNFILLED';
            }
        }

        return 'OK';
    }

    protected function truncate(string $value, int $length = 48): string
    {
        $value = trim(preg_replace('/\s+/', ' ', $value) ?? $value);

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length - 1).'…' : $value;
    }

    /**
     * @param  array{subject: string, html: string, text: string}  $rendered
     */
    protected function wrapHtml(string $code, string $locale, array $rendered): string
    {
        $subject = e($rendered['subject']);
        $meta = e("{$code} · {$locale}");

        return <<<HTML
        <!doctype html>
        <html lang="{$locale}">
        <head><meta charset="utf-8"><title>{$subject}</title></head>
        <body style="font-family:system-ui,sans-serif;margin:0;background:#f4f4f5">
          <div style="padding:12px 20px;background:#18181b;color:#fff;font-size:13px">{$meta} — <strong>{$subject}</strong></div>
          <div style="max-width:680px;margin:24px auto;background:#fff;padding:24px;border-radius:8px">
            {$rendered['html']}
          </div>
          <div style="max-width:680px;margin:0 auto 24px;padding:16px 24px;background:#fafafa;border-radius:8px;white-space:pre-wrap;font-family:ui-monospace,monospace;font-size:12px;color:#52525b">{$rendered['text']}</div>
        </body>
        </html>
        HTML;
    }
}
