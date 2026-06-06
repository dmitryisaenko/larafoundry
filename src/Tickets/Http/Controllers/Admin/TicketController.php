<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tickets\Http\Controllers\Admin;

use Dmitryisaenko\LaraFoundry\ActivityLog\Facades\Activity;
use Dmitryisaenko\LaraFoundry\Notifications\Support\NotificationService;
use Dmitryisaenko\LaraFoundry\Support\Pagination\HasPagination;
use Dmitryisaenko\LaraFoundry\Tickets\Actions\GetTicketsStat;
use Dmitryisaenko\LaraFoundry\Tickets\Events\TicketCreated;
use Dmitryisaenko\LaraFoundry\Tickets\Events\TicketReplied;
use Dmitryisaenko\LaraFoundry\Tickets\Http\Filters\AdminTicketsFilter;
use Dmitryisaenko\LaraFoundry\Tickets\Http\Requests\Admin\ReplyTicketRequest;
use Dmitryisaenko\LaraFoundry\Tickets\Http\Requests\Admin\StoreTicketRequest;
use Dmitryisaenko\LaraFoundry\Tickets\Http\Requests\Admin\UpdateTicketRequest;
use Dmitryisaenko\LaraFoundry\Tickets\Http\Resources\Admin\AdminTicketResource;
use Dmitryisaenko\LaraFoundry\Tickets\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The operator side of the helpdesk (phase 4.2): every ticket on the platform.
 *
 * Reachable only through the `larafoundry.admin` super-admin gate (the route
 * group in routes/admin.php), so there is no per-row policy — the operator sees
 * and manages all tickets (decision D-4.2-2: support is a user↔operator channel,
 * not a tenant feature). Status changes are written to the activity log
 * (security finding S3), and a reply / a ticket opened on the user's behalf
 * pushes an in-app notification to the author (decision D-4.2-3).
 */
class TicketController extends Controller
{
    use HasPagination;

    public function __construct(
        protected GetTicketsStat $stats,
        protected NotificationService $notifications,
    ) {}

    /**
     * The filtered, workflow-ordered operator queue with its counters.
     */
    public function index(Request $request): Response
    {
        $query = (new AdminTicketsFilter($request))
            ->apply(Ticket::query()->with('user')->withCount('messages'));

        $tickets = $query
            ->paginate((int) config('larafoundry-tickets.admin_per_page', 20))
            ->withQueryString();

        return Inertia::render('Admin/Tickets/Index', [
            'tickets' => AdminTicketResource::collection($tickets),
            'pagination' => $this->getPaginationData($tickets),
            'filters' => $request->only(['search', 'status', 'priority', 'show_tickets']),
            'stats' => ($this->stats)(),
            ...$this->lists(),
        ]);
    }

    /**
     * The create form (the operator opens a ticket on a user's behalf).
     */
    public function create(): Response
    {
        return Inertia::render('Admin/Tickets/Create', $this->lists());
    }

    /**
     * Open a ticket for a user. Starts `wait-customer` (the user owes a reply)
     * and notifies the user that support opened it.
     */
    public function store(StoreTicketRequest $request): RedirectResponse
    {
        $ticket = Ticket::create([
            'user_id' => $request->validated('user_id'),
            'title' => $request->validated('title'),
            'message' => $request->validated('message'),
            'priority' => $request->validated('priority') ?: 'standard',
            'status' => Ticket::STATUS_WAIT_CUSTOMER,
            'categories' => $request->validated('categories', []),
            'labels' => $request->validated('labels', []),
        ]);

        event(new TicketCreated($request->user(), $ticket));
        $this->notifyAuthor($ticket, 'opened');

        return redirect()
            ->route('admin.tickets.show', $ticket)
            ->with('success', __('larafoundry::tickets.created'));
    }

    /**
     * One ticket with its full conversation.
     */
    public function show(Ticket $ticket): Response
    {
        $ticket->load(['user', 'messages.user']);

        return Inertia::render('Admin/Tickets/Show', [
            'ticket' => (new AdminTicketResource($ticket))->resolve(),
            ...$this->lists(),
        ]);
    }

    /**
     * The edit form.
     */
    public function edit(Ticket $ticket): Response
    {
        $ticket->load('user');

        return Inertia::render('Admin/Tickets/Edit', [
            'ticket' => (new AdminTicketResource($ticket))->resolve(),
            ...$this->lists(),
        ]);
    }

    /**
     * Update a ticket's fields. A status change is audited (finding S3).
     */
    public function update(UpdateTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        $previousStatus = $ticket->status;

        $ticket->fill($request->safe()->only(['title', 'message', 'priority', 'status']));

        if ($request->has('categories')) {
            $ticket->categories = $request->validated('categories', []);
        }

        if ($request->has('labels')) {
            $ticket->labels = $request->validated('labels', []);
        }

        $ticket->save();

        if ($ticket->status !== $previousStatus) {
            $this->audit('admin.ticket.status_changed', $ticket, [
                'from' => $previousStatus,
                'to' => $ticket->status,
            ]);
        }

        return redirect()
            ->route('admin.tickets.show', $ticket)
            ->with('success', __('larafoundry::tickets.updated'));
    }

    /**
     * Operator reply. Moves the ticket to `wait-customer` and notifies the user.
     */
    public function reply(ReplyTicketRequest $request, Ticket $ticket): RedirectResponse
    {
        $ticket->messages()->create([
            'user_id' => $request->user()->getKey(),
            'message' => $request->validated('message'),
        ]);

        $ticket->update(['status' => Ticket::STATUS_WAIT_CUSTOMER]);

        event(new TicketReplied($request->user(), $ticket, byOperator: true));
        $this->notifyAuthor($ticket, 'reply');

        return redirect()
            ->route('admin.tickets.show', $ticket)
            ->with('success', __('larafoundry::tickets.replied'));
    }

    /**
     * Close (resolve) a ticket and audit it.
     */
    public function close(Ticket $ticket): RedirectResponse
    {
        $previousStatus = $ticket->status;
        $ticket->update(['status' => Ticket::STATUS_RESOLVED]);

        $this->audit('admin.ticket.closed', $ticket, ['from' => $previousStatus]);

        return back()->with('success', __('larafoundry::tickets.closed'));
    }

    /**
     * Patch the priority and audit it.
     */
    public function updatePriority(Request $request, Ticket $ticket): RedirectResponse
    {
        $validated = $request->validate([
            'priority' => ['required', 'string', Rule::in($this->slugs('priorities'))],
        ]);

        $ticket->update(['priority' => $validated['priority']]);

        $this->audit('admin.ticket.priority_changed', $ticket, ['to' => $validated['priority']]);

        return back()->with('success', __('larafoundry::tickets.priority_updated'));
    }

    /**
     * Add/remove a category slug on the ticket (JSON array) and audit it.
     */
    public function toggleCategory(Request $request, Ticket $ticket): RedirectResponse
    {
        return $this->toggleTag($request, $ticket, 'categories');
    }

    /**
     * Add/remove a label slug on the ticket (JSON array) and audit it.
     */
    public function toggleLabel(Request $request, Ticket $ticket): RedirectResponse
    {
        return $this->toggleTag($request, $ticket, 'labels');
    }

    /**
     * Shared add/remove for the categories/labels JSON-array columns.
     */
    protected function toggleTag(Request $request, Ticket $ticket, string $field): RedirectResponse
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', Rule::in($this->slugs($field))],
        ]);

        $slug = $validated['slug'];
        $current = (array) ($ticket->{$field} ?? []);

        if (in_array($slug, $current, true)) {
            $current = array_values(array_diff($current, [$slug]));
            $messageKey = $field === 'labels' ? 'label_removed' : 'category_removed';
        } else {
            $current[] = $slug;
            $messageKey = $field === 'labels' ? 'label_added' : 'category_added';
        }

        $ticket->update([$field => $current]);

        $this->audit("admin.ticket.{$field}_toggled", $ticket, ['slug' => $slug]);

        return back()->with('success', __("larafoundry::tickets.{$messageKey}"));
    }

    /**
     * Push the in-app system notification to a ticket's author (phase 4.1 seam).
     *
     * Title and body are resolved per-reader by the notification model (they are
     * stored as translation keys). The action label is part of the static `data`
     * payload, which the notification store does NOT re-localise at read time, so
     * it is baked here in the recipient's stored locale when known, falling back
     * to the current locale otherwise (a minor, accepted limitation shared by any
     * system-notification action). No-op when disabled or the author is missing.
     */
    protected function notifyAuthor(Ticket $ticket, string $kind): void
    {
        if (! config('larafoundry-tickets.notifications.enabled', true)) {
            return;
        }

        $author = $ticket->user()->first();

        if ($author === null) {
            return;
        }

        $locale = method_exists($author, 'preferredLocale')
            ? ($author->preferredLocale() ?? app()->getLocale())
            : app()->getLocale();

        $this->notifications->system(
            users: [$author],
            code: 'info',
            titleKey: "larafoundry::tickets.notify.{$kind}.title",
            bodyKey: "larafoundry::tickets.notify.{$kind}.body",
            params: ['title' => $ticket->title],
            data: ['actions' => [[
                'label' => __('larafoundry::tickets.notify.action_view', [], $locale),
                'url' => '/tickets/'.$ticket->uuid,
            ]]],
        );
    }

    /**
     * Write one operator action to the activity log (admin log name), with the
     * ticket as the subject. Geo is enqueued so a slow provider never blocks.
     *
     * @param  array<string, mixed>  $properties
     */
    protected function audit(string $description, Model $ticket, array $properties = []): void
    {
        Activity::log(
            description: $description,
            logName: 'admin',
            properties: array_merge(['target_id' => $ticket->getKey()], $properties),
            subject: $ticket,
            geoSync: false,
        );
    }

    /**
     * The configured slug lists handed to every console screen.
     *
     * @return array{categories: array<int, string>, labels: array<int, string>, priorities: array<int, string>, statuses: array<int, string>}
     */
    protected function lists(): array
    {
        return [
            'categories' => $this->slugs('categories'),
            'labels' => $this->slugs('labels'),
            'priorities' => $this->slugs('priorities'),
            'statuses' => $this->slugs('statuses'),
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function slugs(string $key): array
    {
        return (array) config("larafoundry-tickets.{$key}", []);
    }
}
