<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Email\Http\Controllers\Admin;

use Dmitryisaenko\LaraFoundry\ActivityLog\Facades\Activity;
use Dmitryisaenko\LaraFoundry\Email\Http\Requests\PreviewEmailTemplateRequest;
use Dmitryisaenko\LaraFoundry\Email\Http\Requests\SendTestEmailRequest;
use Dmitryisaenko\LaraFoundry\Email\Http\Requests\UpdateEmailTemplateRequest;
use Dmitryisaenko\LaraFoundry\Email\Models\EmailTemplate;
use Dmitryisaenko\LaraFoundry\Email\Support\EmailTemplateRepository;
use Dmitryisaenko\LaraFoundry\Email\Support\HtmlSanitizer;
use Dmitryisaenko\LaraFoundry\Email\Support\TemplateRenderer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The email-template editor in the operator console (phase 5.1), super-admin
 * only.
 *
 * Edit-only by design (decision D-5.1-10): an operator edits the subject/body of
 * the templates the platform ships (resolved from the config registry), previews
 * a render and sends a test — but cannot create or delete templates, since a
 * template is only ever sent by a Notification that names its `code`. New
 * templates arrive by adding a registry entry + a Notification, and this editor
 * picks them up automatically. The whole zone is behind `larafoundry.admin`
 * (+ OTP); each action re-checks the super-admin policy as defence-in-depth.
 */
class EmailTemplateController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly EmailTemplateRepository $templates,
        private readonly TemplateRenderer $renderer,
        private readonly HtmlSanitizer $sanitizer,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', EmailTemplate::class);

        return Inertia::render('Admin/EmailTemplates/Index', [
            'templates' => $this->templates->all(),
        ]);
    }

    public function edit(string $code): Response
    {
        $this->authorize('view', EmailTemplate::class);

        $template = $this->templates->find($code);

        abort_if($template === null, 404);

        return Inertia::render('Admin/EmailTemplates/Edit', [
            'template' => $template,
            'locales' => $this->templates->availableLocales(),
        ]);
    }

    public function update(string $code, UpdateEmailTemplateRequest $request): RedirectResponse
    {
        abort_unless($this->templates->isRegistered($code), 404);

        $this->templates->save($code, [
            'is_active' => $request->boolean('is_active'),
            'subject' => (array) $request->input('subject', []),
            'body_html' => (array) $request->input('body_html', []),
            'body_text' => (array) $request->input('body_text', []),
        ]);

        Activity::log(
            description: 'admin.email_template.updated',
            logName: 'admin',
            // NOT 'code' — that key is in pii_redact_keys (OTP/2FA codes) and would
            // be masked to [redacted], losing which template was edited.
            properties: ['template_code' => $code, 'active' => $request->boolean('is_active')],
            geoSync: false,
        );

        return back()->with('status', __('larafoundry::email.saved'));
    }

    /**
     * Render unsaved editor input for one locale with sample data, server-side
     * purified — the editor shows it in a sandboxed iframe.
     */
    public function preview(string $code, PreviewEmailTemplateRequest $request): JsonResponse
    {
        abort_unless($this->templates->isRegistered($code), 404);

        $data = $this->templates->sampleData($code);

        return response()->json([
            'subject' => $this->renderer->render((string) $request->input('subject', ''), $data),
            'html' => $this->sanitizer->clean($this->renderer->render((string) $request->input('body_html', ''), $data)),
            'text' => $this->renderer->render((string) $request->input('body_text', ''), $data),
        ]);
    }

    /**
     * Send the saved (or default) template, rendered with sample data, to an
     * address. Rate-limited at the route.
     */
    public function sendTest(string $code, SendTestEmailRequest $request): RedirectResponse
    {
        abort_unless($this->templates->isRegistered($code), 404);

        $rendered = $this->templates->render(
            $code,
            (string) $request->input('locale'),
            $this->templates->sampleData($code),
        );

        if ($rendered === null) {
            return back()->withErrors(['email' => __('larafoundry::email.inactive')]);
        }

        $recipient = (string) $request->input('email');

        Mail::send([], [], function ($message) use ($recipient, $rendered) {
            $message->to($recipient)
                ->subject('[TEST] '.$rendered['subject'])
                ->html($rendered['html'])
                ->text($rendered['text']);
        });

        return back()->with('status', __('larafoundry::email.test_sent', ['email' => $recipient]));
    }
}
