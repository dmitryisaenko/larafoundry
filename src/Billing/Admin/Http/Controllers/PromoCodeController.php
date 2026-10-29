<?php

declare(strict_types=1);

namespace Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Controllers;

use Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Filters\AdminPromoCodesFilter;
use Dmitryisaenko\LaraFoundry\Billing\Admin\Http\Requests\AdminPromoCodesFilterRequest;
use Dmitryisaenko\LaraFoundry\Billing\Http\Requests\StorePromoCodeRequest;
use Dmitryisaenko\LaraFoundry\Billing\Http\Requests\UpdatePromoCodeRequest;
use Dmitryisaenko\LaraFoundry\Billing\Models\PromoCode;
use Dmitryisaenko\LaraFoundry\Support\Pagination\HasPagination;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as DefaultUser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Super-admin promo-code CRUD console (sub-phase B7b), ported from the donor's
 * `App\Http\Controllers\Admin\PromoCodeController`. Closes B5 debt D-B5-4: the
 * Store/Update FormRequests shipped in B5 with no routes/UI; this gives them both.
 *
 * Reached only through the `larafoundry.admin` route gate. Every action is a second
 * lock on {@see PromoCode::MANAGE_ABILITY} (the gate the provider defines, mapped to
 * super-admin): the store/update FormRequests authorize via it, the other actions check
 * it explicitly here. Codes are platform-global (not tenant-scoped), so any super-admin
 * may manage any code — there is no IDOR surface beyond the gate.
 */
class PromoCodeController extends Controller
{
    use HasPagination;

    public function index(AdminPromoCodesFilterRequest $request): Response
    {
        $this->authorizeManage();

        $sortBy = (string) $request->input('sort_by', 'created_at');
        $sortOrder = (string) $request->input('sort_order', 'desc');

        $promoCodes = (new AdminPromoCodesFilter($request))
            ->apply(PromoCode::query()->with('user')->withCount(['redemptions as total_uses']))
            ->orderBy($sortBy, $sortOrder)
            ->paginate($this->perPage())
            ->withQueryString()
            ->through(fn (PromoCode $code): array => [
                'id' => (int) $code->id,
                'code' => $code->code,
                'description' => $code->description,
                'kind' => $code->kind,
                'discount_type' => $code->discount_type,
                'discount_value' => $code->discount_value,
                'max_uses' => $code->max_uses,
                'used_count' => $code->used_count,
                'total_uses' => (int) ($code->total_uses ?? 0),
                'single_use_per_user' => $code->single_use_per_user,
                'is_active' => $code->is_active,
                'expires_at' => $code->expires_at?->format('Y-m-d H:i'),
                'user_id' => $code->user_id,
                'user_email' => $code->user?->email,
                'user_fullname' => $this->fullname($code->user),
                'is_personal' => $code->isPersonal(),
                'status' => $code->getStatus(),
                'created_at' => $code->created_at?->format('Y-m-d H:i'),
            ]);

        return Inertia::render('Admin/PromoCodes/Index', [
            'promo_codes' => $promoCodes,
            'filters' => [
                'search' => $request->input('search'),
                'status' => $request->input('status'),
                'type' => $request->input('type'),
                'sort_by' => $sortBy,
                'sort_order' => $sortOrder,
            ],
            'pagination' => $this->getPaginationData($promoCodes),
        ]);
    }

    public function create(): Response
    {
        $this->authorizeManage();

        return Inertia::render('Admin/PromoCodes/Create', [
            'discount_types' => $this->discountTypes(),
            'kinds' => $this->kinds(),
        ]);
    }

    public function store(StorePromoCodeRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $data['code'] = strtoupper((string) $data['code']);

        PromoCode::create($data);

        return redirect()
            ->route('admin.promo-codes.index')
            ->with('success', __('larafoundry::billing.admin.promo.created'));
    }

    public function edit(PromoCode $promoCode): Response
    {
        $this->authorizeManage();

        return Inertia::render('Admin/PromoCodes/Edit', [
            'promo_code' => [
                'id' => (int) $promoCode->id,
                'code' => $promoCode->code,
                'description' => $promoCode->description,
                'kind' => $promoCode->kind,
                'discount_type' => $promoCode->discount_type,
                'discount_value' => $promoCode->discount_value,
                'stripe_coupon_id' => $promoCode->stripe_coupon_id,
                'paddle_discount_id' => $promoCode->paddle_discount_id,
                'max_uses' => $promoCode->max_uses,
                'used_count' => $promoCode->used_count,
                'single_use_per_user' => $promoCode->single_use_per_user,
                'is_active' => $promoCode->is_active,
                'expires_at' => $promoCode->expires_at?->format('Y-m-d\TH:i'),
                'user_id' => $promoCode->user_id,
                'user_email' => $promoCode->user?->email,
                'user_fullname' => $this->fullname($promoCode->user),
            ],
            'discount_types' => $this->discountTypes(),
            'kinds' => $this->kinds(),
        ]);
    }

    public function update(UpdatePromoCodeRequest $request, PromoCode $promoCode): RedirectResponse
    {
        $data = $request->validated();

        if (array_key_exists('code', $data)) {
            $data['code'] = strtoupper((string) $data['code']);
        }

        $promoCode->update($data);

        return redirect()
            ->route('admin.promo-codes.index')
            ->with('success', __('larafoundry::billing.admin.promo.updated'));
    }

    public function toggleActive(PromoCode $promoCode): RedirectResponse
    {
        $this->authorizeManage();

        $promoCode->update(['is_active' => ! $promoCode->is_active]);

        return back()->with('success', $promoCode->is_active
            ? __('larafoundry::billing.admin.promo.activated')
            : __('larafoundry::billing.admin.promo.deactivated'));
    }

    /**
     * Type-ahead user lookup for assigning a personal code.
     *
     * Gated, length-bounded and route-throttled. Resolves the host's configured auth
     * user model (never a hardcoded class) and returns only {id, email, fullname} — no
     * other PII. The donor's equivalent had no throttle and no min-length guard.
     */
    public function searchUsers(Request $request): JsonResponse
    {
        $this->authorizeManage();

        // Bound the term: at least min_length to search, and capped so an oversized
        // string never reaches the LIKE (symmetry with the filter requests' max:255).
        $search = mb_substr(trim((string) $request->input('search', '')), 0, 255);
        $minLength = (int) config('larafoundry-billing.admin.user_search.min_length', 2);

        if (mb_strlen($search) < $minLength) {
            return response()->json([]);
        }

        $limit = (int) config('larafoundry-billing.admin.user_search.limit', 10);

        /** @var class-string<Model> $model */
        $model = config('auth.providers.users.model', DefaultUser::class);

        $users = $model::query()
            ->where(function ($query) use ($search) {
                $query->where('email', 'like', '%'.$search.'%')
                    ->orWhere('name', 'like', '%'.$search.'%')
                    ->orWhere('lastname', 'like', '%'.$search.'%');
            })
            ->limit($limit)
            ->get();

        return response()->json(
            $users->map(fn ($user): array => [
                'id' => $user->getKey(),
                'email' => $user->email,
                'fullname' => $this->fullname($user),
            ])->values()
        );
    }

    /**
     * Second-lock authorization for the actions that do not use a gated FormRequest.
     */
    protected function authorizeManage(): void
    {
        abort_unless(Gate::allows(PromoCode::MANAGE_ABILITY), 403);
    }

    /**
     * Compose a user's full name (the core user has no getFullname()); empty when null.
     */
    protected function fullname(?object $user): string
    {
        if (! is_object($user)) {
            return '';
        }

        return trim((string) ($user->name ?? '').' '.(string) ($user->lastname ?? ''));
    }

    /**
     * @return list<array{value: string, name: string}>
     */
    protected function discountTypes(): array
    {
        return [
            ['value' => 'percentage', 'name' => 'Percentage'],
            ['value' => 'fixed', 'name' => 'Fixed amount'],
        ];
    }

    /**
     * @return list<array{value: string, name: string}>
     */
    protected function kinds(): array
    {
        return [
            ['value' => PromoCode::KIND_DISCOUNT, 'name' => 'Discount'],
            ['value' => PromoCode::KIND_FREE_MONTH, 'name' => 'Free month'],
        ];
    }

    protected function perPage(): int
    {
        return (int) config('larafoundry-billing.admin.promo_codes_per_page', 20);
    }
}
