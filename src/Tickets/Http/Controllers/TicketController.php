<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Tickets\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Support\Pagination\HasPagination;
use Dmitryisaenko\LaraFoundry\Tickets\Events\TicketCreated;
use Dmitryisaenko\LaraFoundry\Tickets\Events\TicketReplied;
use Dmitryisaenko\LaraFoundry\Tickets\Http\Requests\StoreTicketMessageRequest;
use Dmitryisaenko\LaraFoundry\Tickets\Http\Requests\StoreTicketRequest;
use Dmitryisaenko\LaraFoundry\Tickets\Http\Resources\TicketResource;
use Dmitryisaenko\LaraFoundry\Tickets\Models\Ticket;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The customer side of the helpdesk (phase 4.2): a user's own tickets.
 *
 * Reachable by ANY authenticated user — including a blocked one (decision: when
 * a company is blocked, support is the only channel left to reach the operator),
 * so the user routes carry `auth` but NOT the account-active gate. Every action
 * is scoped to the caller's own tickets (the ownership policy), and the uuid is
 * the route key so the sequential id is never exposed.
 */
class TicketController extends Controller
{
    use AuthorizesRequests;
    use HasPagination;

    /**
     * The user's own tickets, recent-resolved hidden, workflow-ordered.
     */
    public function index(): Response
    {
        $this->authorize('viewAny', Ticket::class);

        $tickets = Ticket::query()
            ->where('user_id', auth()->id())
            ->excludeOldResolved()
            ->withCount('messages')
            ->workflowOrder()
            ->paginate((int) config('larafoundry-tickets.per_page', 15));

        return Inertia::render('Tickets/Index', [
            'tickets' => TicketResource::collection($tickets),
            'pagination' => $this->getPaginationData($tickets),
            'categories' => $this->categories(),
            'priorities' => $this->priorities(),
        ]);
    }

    /**
     * The create form.
     */
    public function create(): Response
    {
        return Inertia::render('Tickets/Create', [
            'categories' => $this->categories(),
            'priorities' => $this->priorities(),
        ]);
    }

    /**
     * Open a new ticket. Starts `wait-moderator` — the operator's queue.
     */
    public function store(StoreTicketRequest $request): RedirectResponse
    {
        $ticket = Ticket::create([
            'user_id' => $request->user()->getKey(),
            'title' => $request->validated('title'),
            'message' => $request->validated('message'),
            'priority' => $request->validated('priority') ?: 'standard',
            'status' => Ticket::STATUS_WAIT_MODERATOR,
            'categories' => $request->validated('categories', []),
        ]);

        event(new TicketCreated($request->user(), $ticket));

        return redirect()
            ->route('tickets.show', $ticket->uuid)
            ->with('success', __('larafoundry::tickets.created'));
    }

    /**
     * View one of the user's own tickets with its conversation.
     */
    public function show(Ticket $ticket): Response
    {
        $this->authorize('view', $ticket);

        $ticket->load(['messages.user']);

        return Inertia::render('Tickets/Show', [
            'ticket' => (new TicketResource($ticket))->resolve(),
        ]);
    }

    /**
     * Post a reply. Reopens the ticket (back to `wait-moderator`) whatever its
     * prior status — replying to a resolved ticket reopens it.
     */
    public function storeMessage(StoreTicketMessageRequest $request, Ticket $ticket): RedirectResponse
    {
        $this->authorize('reply', $ticket);

        $ticket->messages()->create([
            'user_id' => $request->user()->getKey(),
            'message' => $request->validated('message'),
        ]);

        $ticket->update(['status' => Ticket::STATUS_WAIT_MODERATOR]);

        event(new TicketReplied($request->user(), $ticket, byOperator: false));

        return back()->with('success', __('larafoundry::tickets.message_sent'));
    }

    /**
     * @return array<int, string>
     */
    protected function categories(): array
    {
        return (array) config('larafoundry-tickets.categories', []);
    }

    /**
     * @return array<int, string>
     */
    protected function priorities(): array
    {
        return (array) config('larafoundry-tickets.priorities', ['standard', 'high']);
    }
}
