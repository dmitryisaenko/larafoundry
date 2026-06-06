<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Notifications\Http\Controllers\Admin;

use Dmitryisaenko\LaraFoundry\ActivityLog\Facades\Activity;
use Dmitryisaenko\LaraFoundry\Notifications\Http\Requests\Admin\StoreBroadcastNotificationRequest;
use Dmitryisaenko\LaraFoundry\Notifications\Http\Resources\Admin\AdminBroadcastResource;
use Dmitryisaenko\LaraFoundry\Notifications\Jobs\SendBroadcastNotificationJob;
use Dmitryisaenko\LaraFoundry\Notifications\Models\Notification;
use Dmitryisaenko\LaraFoundry\Support\Pagination\HasPagination;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super-admin broadcast management (phase 4.1).
 *
 * Reachable only through the `larafoundry.admin` gate. An operator drafts a
 * broadcast (per-locale text + an audience filter + an optional visibility
 * window), then sends it: sending is a QUEUED, chunked fan-out
 * ({@see SendBroadcastNotificationJob}), not the donor's synchronous mass-attach
 * (security finding #2). Every create/send/delete is written to the activity log
 * (finding #7 — and the donor's silent catch on update is gone).
 */
class BroadcastNotificationController extends Controller
{
    use HasPagination;

    /**
     * The paginated broadcast list with delivery counters.
     */
    public function index(): Response
    {
        $notifications = Notification::query()
            ->admin()
            ->withCount([
                'users',
                'users as read_count' => fn ($query) => $query->whereNotNull('larafoundry_notification_user.read_at'),
            ])
            ->orderByDesc('created_at')
            ->paginate($this->perPage())
            ->withQueryString();

        return Inertia::render('Admin/Notifications/Index', [
            'notifications' => AdminBroadcastResource::collection($notifications),
            'pagination' => $this->getPaginationData($notifications),
        ]);
    }

    /**
     * The create form.
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Notifications/Create', $this->formOptions());
    }

    /**
     * Persist a new broadcast as a draft.
     */
    public function store(StoreBroadcastNotificationRequest $request): RedirectResponse
    {
        $notification = Notification::create($this->attributesFrom($request->validated()) + ['status' => 'draft']);

        $this->audit('admin.notification.created', $notification);

        return redirect()
            ->route('admin.notifications.index')
            ->with('success', __('larafoundry::notifications.broadcast.created'));
    }

    /**
     * The edit form for a draft.
     */
    public function edit(Notification $notification): Response
    {
        return Inertia::render('Admin/Notifications/Edit', [
            'notification' => [
                'id' => $notification->id,
                'code' => $notification->code,
                'status' => $notification->status,
                'title_translations' => $notification->title_translations,
                'body_translations' => $notification->body_translations,
                'recipients' => $notification->recipient_filters,
                'visible_from' => $notification->visible_from?->format('Y-m-d\TH:i'),
                'visible_until' => $notification->visible_until?->format('Y-m-d\TH:i'),
            ],
            ...$this->formOptions(),
        ]);
    }

    /**
     * Update a draft. Only drafts are editable — once sending/sent the audience
     * is fixed, so editing is refused rather than silently partially applied.
     */
    public function update(StoreBroadcastNotificationRequest $request, Notification $notification): RedirectResponse
    {
        if (! $notification->isDraft()) {
            return back()->with('error', __('larafoundry::notifications.broadcast.not_draft'));
        }

        $notification->update($this->attributesFrom($request->validated()));

        $this->audit('admin.notification.updated', $notification);

        return redirect()
            ->route('admin.notifications.index')
            ->with('success', __('larafoundry::notifications.broadcast.updated'));
    }

    /**
     * Queue the fan-out to the audience. Idempotent against double-submit: only
     * a draft can be sent, and it flips to `sending` before the job is queued.
     */
    public function send(Notification $notification): RedirectResponse
    {
        if (! $notification->isAdmin() || ! $notification->isDraft()) {
            return back()->with('error', __('larafoundry::notifications.broadcast.not_draft'));
        }

        $notification->update(['status' => 'sending']);

        SendBroadcastNotificationJob::dispatch($notification->getKey());

        $this->audit('admin.notification.sent', $notification);

        return redirect()
            ->route('admin.notifications.index')
            ->with('success', __('larafoundry::notifications.broadcast.queued'));
    }

    /**
     * Delete a broadcast and its recipient rows.
     */
    public function destroy(Notification $notification): RedirectResponse
    {
        $notification->users()->detach();
        $notification->delete();

        $this->audit('admin.notification.deleted', $notification);

        return redirect()
            ->route('admin.notifications.index')
            ->with('success', __('larafoundry::notifications.broadcast.deleted'));
    }

    /**
     * Map validated input onto the model's broadcast columns. Drops empty
     * audience facets so `recipient_filters` holds only real constraints.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    protected function attributesFrom(array $validated): array
    {
        $recipients = array_filter(
            $validated['recipients'] ?? [],
            fn ($value) => $value !== null && $value !== '',
        );

        return [
            'code' => $validated['code'],
            'notification_type' => 'admin',
            'title_translations' => $validated['title_translations'],
            'body_translations' => $validated['body_translations'] ?? null,
            'recipient_filters' => $recipients ?: null,
            'visible_from' => $validated['visible_from'] ?? null,
            'visible_until' => $validated['visible_until'] ?? null,
        ];
    }

    /**
     * Shared create/edit form props.
     *
     * @return array<string, mixed>
     */
    protected function formOptions(): array
    {
        return [
            'types' => array_keys((array) config('larafoundry-notifications.types', [])),
            'available_locales' => array_values((array) config('larafoundry.locale.available', ['en'])),
        ];
    }

    /**
     * Write one super-admin broadcast action to the activity log.
     */
    protected function audit(string $description, Notification $notification): void
    {
        Activity::log(
            description: $description,
            logName: 'admin',
            properties: ['target_id' => $notification->getKey(), 'code' => $notification->code],
            subject: $notification,
            geoSync: false,
        );
    }

    protected function perPage(): int
    {
        return (int) config('larafoundry-notifications.per_page', 15);
    }
}
