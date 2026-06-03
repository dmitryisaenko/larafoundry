<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\ActivityLog\Http\Controllers\Admin;

use Dmitryisaenko\LaraFoundry\ActivityLog\Http\Resources\ActivityLogResource;
use Dmitryisaenko\LaraFoundry\Support\Pagination\HasPagination;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super-admin viewer for the platform activity log (phase 2.1).
 *
 * The FIRST screen of the operator console. Reachable only through the
 * `larafoundry.admin` gate (super-admin via VisitorStatus). The log is global —
 * there is no company scoping (decision D2.1-0).
 */
class ActivityLogController extends Controller
{
    use HasPagination;

    /**
     * Allowed values for the `?hours` time-window filter. Anything else falls
     * back to the default below.
     *
     * @var list<int>
     */
    protected array $availableHours = [1, 6, 12, 24, 48, 72];

    /**
     * The whole-platform log.
     */
    public function index(Request $request): Response
    {
        $hours = $this->resolveHours($request, default: 24);

        $logs = $this->model()::query()
            ->inLastHours($hours)
            ->recent()
            ->with('user')
            ->paginate(100)
            ->withQueryString();

        return Inertia::render('Admin/Logs/GeneralLogs', [
            'logs' => ActivityLogResource::collection($logs),
            'pagination' => $this->getPaginationData($logs),
            'selectedHours' => $hours,
            'availableHours' => $this->availableHours,
        ]);
    }

    /**
     * The log for a single user (scoped by causer).
     */
    public function forUser(Request $request, int|string $user): Response
    {
        $target = $this->resolveUser($user);

        $hours = $this->resolveHours($request, default: 1);

        $logs = $this->model()::query()
            ->byCauser($target->getKey())
            ->inLastHours($hours)
            ->recent()
            ->with('user')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Admin/Logs/UserLogs', [
            'targetUser' => [
                'id' => $target->getKey(),
                'name' => $target->name,
                'email' => $target->email,
            ],
            'logs' => ActivityLogResource::collection($logs),
            'pagination' => $this->getPaginationData($logs),
            'selectedHours' => $hours,
            'availableHours' => $this->availableHours,
        ]);
    }

    /**
     * Validate the requested window against the allow-list.
     */
    protected function resolveHours(Request $request, int $default): int
    {
        $hours = (int) $request->integer('hours', $default);

        return in_array($hours, $this->availableHours, true) ? $hours : $default;
    }

    /**
     * @return class-string<Model>
     */
    protected function model(): string
    {
        return config('activitylog.activity_model');
    }

    protected function resolveUser(int|string $id): Authenticatable&Model
    {
        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model');

        /** @var Authenticatable&Model */
        return $model::query()->findOrFail($id);
    }
}
