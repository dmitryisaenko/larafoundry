<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Email\Http\Controllers\Admin;

use Dmitryisaenko\LaraFoundry\ActivityLog\Facades\Activity;
use Dmitryisaenko\LaraFoundry\Email\Http\Requests\PreviewDraftEmailTemplateRequest;
use Dmitryisaenko\LaraFoundry\Email\Http\Requests\PreviewEmailTemplateRequest;
use Dmitryisaenko\LaraFoundry\Email\Http\Requests\SendTestEmailRequest;
use Dmitryisaenko\LaraFoundry\Email\Http\Requests\StoreMarketingEmailTemplateRequest;
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
 * The email-template editor in the operator console (phase 5.1, two layers in
 * phase 2b), super-admin only.
 *
 * Two layers:
 *  - TRANSACTIONAL (registry codes) — edit-only, fail-closed (decision D-5.1-10):
 *    an operator edits the subject/body of the templates the platform ships
 *    (resolved from the config registry), toggles active, previews and sends a
 *    test — but can NEVER create, rename or delete one, since a Notification
 *    names its `code`. New ones arrive by adding a registry entry + a Notification.
 *  - MARKETING (self-contained DB rows) — full CRUD: create, duplicate (always
 *    into a fresh marketing copy, never forking a transactional sender), edit
 *    (incl. name + own variable whitelist) and delete. No registry entry needed.
 *
 * The whole zone is behind `larafoundry.admin` (+ OTP); each action re-checks the
 * super-admin policy as defence-in-depth, and `destroy` additionally 403s a
 * transactional code over the repository's own fail-closed guard.
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

    /**
     * The create screen for a NEW marketing template (transactional codes are
     * never created here).
     */
    public function create(): Response
    {
        $this->authorize('create', EmailTemplate::class);

        return Inertia::render('Admin/EmailTemplates/Create', [
            'locales' => $this->templates->availableLocales(),
        ]);
    }

    public function store(StoreMarketingEmailTemplateRequest $request): RedirectResponse
    {
        $this->authorize('create', EmailTemplate::class);

        $template = $this->templates->createMarketing([
            'code' => (string) $request->input('code'),
            'name' => (string) $request->input('name'),
            'variables' => (array) $request->input('variables', []),
            'is_active' => $request->boolean('is_active'),
            'subject' => (array) $request->input('subject', []),
            'body_html' => (array) $request->input('body_html', []),
            'body_text' => (array) $request->input('body_text', []),
        ]);

        Activity::log(
            description: 'admin.email_template.created',
            logName: 'admin',
            // NOT 'code' — that key is in pii_redact_keys (OTP/2FA codes) and would
            // be masked to [redacted], losing which template was created.
            properties: ['template_code' => $template->code, 'type' => 'marketing'],
            geoSync: false,
        );

        return redirect('/admin/email-templates/'.$template->code.'/edit')
            ->with('status', __('larafoundry::email.created'));
    }

    /**
     * Clone any template into a NEW marketing copy, then open it for editing. A
     * transactional source is never forked into a second code-driven sender —
     * {@see EmailTemplateRepository::duplicate()} always makes a marketing row.
     */
    public function duplicate(string $code): RedirectResponse
    {
        $this->authorize('create', EmailTemplate::class);

        abort_if($this->templates->find($code) === null, 404);

        $copy = $this->templates->duplicate($code);

        Activity::log(
            description: 'admin.email_template.duplicated',
            logName: 'admin',
            properties: ['template_code' => $copy->code, 'type' => 'marketing', 'source_code' => $code],
            geoSync: false,
        );

        return redirect('/admin/email-templates/'.$copy->code.'/edit')
            ->with('status', __('larafoundry::email.duplicated'));
    }

    /**
     * Delete a MARKETING template. Fail-closed at the controller layer, over the
     * repository guard: a transactional/registry code 403s (its Notification must
     * never lose the template), an unknown code 404s.
     */
    public function destroy(string $code): RedirectResponse
    {
        $this->authorize('delete', EmailTemplate::class);

        abort_if($this->templates->isRegistered($code), 403);

        $template = $this->templates->find($code);

        abort_if($template === null || ($template['type'] ?? null) !== 'marketing', 404);

        $this->templates->deleteMarketing($code);

        Activity::log(
            description: 'admin.email_template.deleted',
            logName: 'admin',
            properties: ['template_code' => $code, 'type' => 'marketing'],
            geoSync: false,
        );

        return redirect('/admin/email-templates')->with('status', __('larafoundry::email.deleted'));
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
        $template = $this->templates->find($code);

        abort_if($template === null, 404);

        $isMarketing = ($template['type'] ?? null) === 'marketing';

        $data = [
            'is_active' => $request->boolean('is_active'),
            'subject' => (array) $request->input('subject', []),
            'body_html' => (array) $request->input('body_html', []),
            'body_text' => (array) $request->input('body_text', []),
        ];

        // Only a marketing row carries its own label + whitelist; the two keys are
        // ignored for a transactional row (its type can never flip in save()).
        if ($isMarketing) {
            $data['name'] = $request->input('name');
            $data['variables'] = (array) $request->input('variables', []);
        }

        $this->templates->save($code, $data);

        Activity::log(
            description: 'admin.email_template.updated',
            logName: 'admin',
            // NOT 'code' — that key is in pii_redact_keys (OTP/2FA codes) and would
            // be masked to [redacted], losing which template was edited.
            properties: [
                'template_code' => $code,
                'type' => $isMarketing ? 'marketing' : 'transactional',
                'active' => $request->boolean('is_active'),
            ],
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
        abort_if($this->templates->find($code) === null, 404);

        $data = $this->templates->sampleData($code);

        return response()->json([
            'subject' => $this->renderer->render((string) $request->input('subject', ''), $data),
            'html' => $this->sanitizer->clean($this->renderer->render((string) $request->input('body_html', ''), $data)),
            'text' => $this->renderer->render((string) $request->input('body_text', ''), $data),
        ]);
    }

    /**
     * Render UNSAVED marketing-create input with sample data derived from the
     * submitted variable list — a template being authored has no code to resolve
     * yet. Server-purified, shown in the same sandboxed iframe.
     */
    public function previewDraft(PreviewDraftEmailTemplateRequest $request): JsonResponse
    {
        $this->authorize('create', EmailTemplate::class);

        $data = $this->templates->sampleDataForVariables(
            array_values((array) $request->input('variables', [])),
        );

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
        abort_if($this->templates->find($code) === null, 404);

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
