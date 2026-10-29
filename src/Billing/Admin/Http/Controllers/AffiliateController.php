<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Filters\AdminAffiliatesFilter;
use Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Requests\AdminAffiliatesFilterRequest;
use Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Requests\StoreAffiliateRequest;
use Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Requests\UpdateAffiliateRequest;
use Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Resources\AdminAffiliateResource;
use Dmitryisaenko\LaraFoundry\Billing\Admin\Policies\AffiliatePolicy;
use Dmitryisaenko\LaraFoundry\Billing\Models\Affiliate;
use Dmitryisaenko\LaraFoundry\Billing\Models\AffiliateCommission;
use Dmitryisaenko\LaraFoundry\Billing\Support\AffiliateCodeGenerator;
use Dmitryisaenko\LaraFoundry\Support\Pagination\HasPagination;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as DefaultUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super-admin affiliates console (Ф4.4): a filtered, paginated list of partners with their
 * referral and commission counts, plus enrol (the `admin` eligibility path) and edit
 * (override rate / status). Reached only through the `larafoundry.admin` gate; the
 * {@see AffiliatePolicy} is the second lock at each action.
 */
class AffiliateController extends Controller
{
    use HasPagination;

    public function __construct(
        protected AffiliatePolicy $policy,
    ) {}

    public function index(AdminAffiliatesFilterRequest $request): Response
    {
        abort_unless($this->policy->viewAny($request->user()), 403);

        $sortBy = (string) $request->input('sort_by', 'created_at');
        $sortOrder = (string) $request->input('sort_order', 'desc');

        $affiliates = (new AdminAffiliatesFilter($request))
            ->apply(Affiliate::query()->with('user')->withCount([
                'referrals',
                'commissions as pending_count' => fn ($q) => $q->where('status', AffiliateCommission::STATUS_PENDING),
                'commissions as approved_count' => fn ($q) => $q->where('status', AffiliateCommission::STATUS_APPROVED),
                'commissions as paid_count' => fn ($q) => $q->where('status', AffiliateCommission::STATUS_PAID),
            ]))
            ->orderBy($sortBy, $sortOrder)
            ->orderByDesc('id')
            ->paginate($this->perPage())
            ->withQueryString();

        return Inertia::render('Admin/Affiliates/Index', [
            'affiliates' => AdminAffiliateResource::collection($affiliates),
            'pagination' => $this->getPaginationData($affiliates),
            'filters' => [
                'status' => $request->input('status'),
                'code' => $request->input('code'),
                'email' => $request->input('email'),
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
            ],
            // The Vue shows the enrol form only in admin eligibility (auto / self_serve
            // create their partners elsewhere); a super-admin can still always create.
            'eligibility' => (string) config('larafoundry-billing.affiliate.eligibility', 'auto'),
        ]);
    }

    /**
     * Autocomplete for the enrol form: host users matched by name/email (admin only). The
     * route throttles it; the query is bounded and the user model is the configured one.
     */
    public function searchUsers(Request $request): JsonResponse
    {
        abort_unless($this->policy->manage($request->user()), 403);

        $config = (array) config('larafoundry-billing.admin.user_search', []);
        $min = is_int($config['min_length'] ?? null) ? $config['min_length'] : 2;
        $limit = is_int($config['limit'] ?? null) ? $config['limit'] : 10;

        $query = mb_substr(trim((string) $request->query('q', '')), 0, 255);

        if (mb_strlen($query) < $min) {
            return response()->json(['data' => []]);
        }

        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model', DefaultUser::class);

        $users = $model::query()
            ->where(function ($q) use ($query) {
                $q->where('email', 'like', '%'.$query.'%')
                    ->orWhere('name', 'like', '%'.$query.'%')
                    ->orWhere('lastname', 'like', '%'.$query.'%');
            })
            ->limit($limit)
            ->get(['id', 'name', 'lastname', 'email']);

        return response()->json([
            'data' => $users->map(fn ($u): array => [
                'id' => $u->id,
                'email' => $u->email,
                'fullname' => trim((string) ($u->name ?? '').' '.(string) ($u->lastname ?? '')),
            ])->all(),
        ]);
    }

    public function store(StoreAffiliateRequest $request): RedirectResponse
    {
        abort_unless($this->policy->manage($request->user()), 403);

        $data = $request->validated();
        $status = $request->statusOrDefault();

        Affiliate::query()->create([
            'user_id' => (int) $data['user_id'],
            'code' => app(AffiliateCodeGenerator::class)->generate(),
            'commission_rate_bp' => $data['commission_rate_bp'] ?? null,
            'status' => $status,
            'approved_at' => $status === Affiliate::STATUS_ACTIVE ? now() : null,
        ]);

        return back()->with('success', __('larafoundry::billing.admin.affiliate.created'));
    }

    public function update(UpdateAffiliateRequest $request, Affiliate $affiliate): RedirectResponse
    {
        abort_unless($this->policy->manage($request->user()), 403);

        $data = $request->validated();
        $attributes = [];

        // Partial update: only touch the keys the form actually sent. A present null
        // commission_rate_bp clears the override; an absent one leaves it unchanged.
        if (array_key_exists('commission_rate_bp', $data)) {
            $attributes['commission_rate_bp'] = $data['commission_rate_bp'];
        }

        if (! empty($data['status'])) {
            $attributes['status'] = $data['status'];

            if ($data['status'] === Affiliate::STATUS_ACTIVE && $affiliate->approved_at === null) {
                $attributes['approved_at'] = now();
            }
        }

        if ($attributes !== []) {
            $affiliate->update($attributes);
        }

        return back()->with('success', __('larafoundry::billing.admin.affiliate.updated'));
    }

    protected function perPage(): int
    {
        return (int) config('larafoundry-billing.affiliate.console.per_page', 25);
    }
}
