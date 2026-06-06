<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Notifications\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Notifications\Http\Resources\UserNotificationResource;
use Dmitryisaenko\LaraFoundry\Support\Pagination\HasPagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The recipient's own notification centre (phase 4.1).
 *
 * Every query goes through `appNotifications()` on the authenticated user, so a
 * caller can only ever see or mutate their OWN notifications — referencing
 * another user's id simply finds nothing (security finding #3, IDOR). The bell
 * dropdown polls `recent`/`unread-count` (JSON); the full inbox is an Inertia
 * page.
 */
class NotificationController extends Controller
{
    use HasPagination;

    /**
     * The paginated inbox.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $notifications = $user->appNotifications()
            ->visible()
            ->paginate($this->perPage())
            ->withQueryString();

        return Inertia::render('Notifications/Index', [
            'notifications' => UserNotificationResource::collection($notifications),
            'pagination' => $this->getPaginationData($notifications),
            'unread_count' => $this->unreadCountFor($request),
        ]);
    }

    /**
     * Recent notifications for the bell dropdown (opened on demand).
     */
    public function recent(Request $request): JsonResponse
    {
        $user = $request->user();

        $recent = $user->appNotifications()
            ->visible()
            ->limit($this->pulloutLimit())
            ->get();

        return response()->json([
            'notifications' => UserNotificationResource::collection($recent),
            'unread_count' => $this->unreadCountFor($request),
        ]);
    }

    /**
     * The unread badge count alone — the cheap endpoint the bell polls.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread_count' => $this->unreadCountFor($request)]);
    }

    /**
     * Mark one of the user's notifications read.
     */
    public function read(Request $request, int $notification): JsonResponse
    {
        $user = $request->user();

        $own = $user->appNotifications()
            ->where('larafoundry_notifications.id', $notification)
            ->first();

        if ($own === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if ($own->pivot->read_at === null) {
            $user->appNotifications()->updateExistingPivot($notification, ['read_at' => now()]);
        }

        return response()->json(['ok' => true, 'unread_count' => $this->unreadCountFor($request)]);
    }

    /**
     * Mark every unread notification of the user read in one query.
     *
     * Goes straight at the pivot scoped by `user_id`, so it touches only this
     * user's rows and never loads the set into memory.
     */
    public function readAll(Request $request): JsonResponse
    {
        $marked = DB::table('larafoundry_notification_user')
            ->where('user_id', $request->user()->getKey())
            ->whereNull('read_at')
            ->update(['read_at' => now(), 'updated_at' => now()]);

        return response()->json(['ok' => true, 'marked' => $marked, 'unread_count' => 0]);
    }

    protected function unreadCountFor(Request $request): int
    {
        return (int) $request->user()
            ->appNotifications()
            ->wherePivot('read_at', null)
            ->count();
    }

    protected function perPage(): int
    {
        return (int) config('larafoundry-notifications.per_page', 15);
    }

    protected function pulloutLimit(): int
    {
        return (int) config('larafoundry-notifications.pullout_limit', 5);
    }
}
