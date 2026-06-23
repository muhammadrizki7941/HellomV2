<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\AuditLog;
use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\ProductPurchase;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Hellom\PosProvisioningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SuperAdminController extends BaseApiController
{
    // ─── Analytics / Monitoring ───

    public function dashboardStats(Request $request): JsonResponse
    {
        $days = min((int) ($request->query('days') ?: 30), 90);
        $since = now()->subDays($days);

        $totalOrgs = Organization::query()->count();
        $totalUsers = User::query()->count();
        $newUsersInPeriod = User::query()->where('created_at', '>=', $since)->count();
        $newOrgsInPeriod = Organization::query()->where('created_at', '>=', $since)->count();

        $activeSubscriptions = Subscription::query()->where('status', 'active')->count();
        $totalSubscriptions = Subscription::query()->count();

        $paidEntitlements = Entitlement::query()->where('status', 'active')
            ->whereHas('plan', fn($q) => $q->where('type', '!=', 'free'))
            ->count();

        $appUsage = AppCatalog::query()
            ->select('apps.id', 'apps.name', 'apps.slug')
            ->withCount(['entitlements as active_count' => fn($q) => $q->where('status', 'active')])
            ->get();

        return $this->ok([
            'period_days' => $days,
            'organizations' => [
                'total' => $totalOrgs,
                'new_in_period' => $newOrgsInPeriod,
            ],
            'users' => [
                'total' => $totalUsers,
                'new_in_period' => $newUsersInPeriod,
            ],
            'subscriptions' => [
                'active' => $activeSubscriptions,
                'total' => $totalSubscriptions,
            ],
            'paid_entitlements' => $paidEntitlements,
            'app_usage' => $appUsage,
        ], 'Dashboard stats');
    }

    // ─── Organization Management ───

    public function listOrganizations(Request $request): JsonResponse
    {
        $limit = min((int) ($request->query('limit') ?: 20), 100);
        $status = $request->query('status');
        $search = $request->query('search');

        $query = Organization::query()->withCount('users');

        if ($status) {
            $query->where('status', $status);
        }
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        $orgs = $query->orderByDesc('created_at')->paginate($limit);

        return $this->ok([
            'items' => $orgs->items(),
            'pagination' => [
                'total' => $orgs->total(),
                'per_page' => $orgs->perPage(),
                'current_page' => $orgs->currentPage(),
                'last_page' => $orgs->lastPage(),
            ],
        ], 'Organizations list');
    }

    public function showOrganization(int $organizationId): JsonResponse
    {
        $org = Organization::query()
            ->withCount('users')
            ->with(['users:id,name,email,role'])
            ->find($organizationId);

        if (!$org) {
            return $this->fail('Organization not found', ['code' => 'NOT_FOUND'], 404);
        }

        $entitlements = Entitlement::query()
            ->where('organization_id', $organizationId)
            ->with('app:id,name,slug', 'plan:id,name,slug')
            ->get();

        return $this->ok([
            'organization' => $org,
            'entitlements' => $entitlements,
        ], 'Organization detail');
    }

    public function suspendOrganization(Request $request, int $organizationId): JsonResponse
    {
        $org = Organization::query()->find($organizationId);
        if (!$org) {
            return $this->fail('Organization not found', ['code' => 'NOT_FOUND'], 404);
        }

        $oldStatus = $org->status;
        $org->update(['status' => 'suspended']);

        $this->audit($request, 'organization.suspend', 'Organization', $organizationId, [
            'old_status' => $oldStatus,
        ]);

        return $this->ok(['id' => $org->id, 'status' => 'suspended'], __('hellom.org_suspended'));
    }

    public function reactivateOrganization(Request $request, int $organizationId): JsonResponse
    {
        $org = Organization::query()->find($organizationId);
        if (!$org) {
            return $this->fail('Organization not found', ['code' => 'NOT_FOUND'], 404);
        }

        $oldStatus = $org->status;
        $org->update(['status' => 'active']);

        $this->audit($request, 'organization.reactivate', 'Organization', $organizationId, [
            'old_status' => $oldStatus,
        ]);

        return $this->ok(['id' => $org->id, 'status' => 'active'], __('hellom.org_reactivated'));
    }

    /**
     * Per-organization outlet quota override (exception above the plan's max_outlets).
     * Null clears the override so the plan limit applies again.
     */
    public function updateOrganizationOutletLimit(Request $request, int $organizationId): JsonResponse
    {
        $org = Organization::query()->find($organizationId);
        if (!$org) {
            return $this->fail('Organization not found', ['code' => 'NOT_FOUND'], 404);
        }

        $validated = $request->validate([
            'max_outlets_override' => ['nullable', 'integer', 'min:1'],
        ]);

        $old = ['max_outlets_override' => $org->max_outlets_override];
        $org->update(['max_outlets_override' => $validated['max_outlets_override'] ?? null]);

        $this->audit($request, 'organization.outlet_limit', 'Organization', $organizationId, $old, $validated);

        return $this->ok($org->fresh(), 'Outlet limit updated');
    }

    // ─── User Management ───

    public function listUsers(Request $request): JsonResponse
    {
        $limit = min((int) ($request->query('limit') ?: 20), 100);
        $role = $request->query('role');
        $search = $request->query('search');

        $query = User::query()->select('id', 'name', 'email', 'role', 'created_at', 'current_organization_id')->with('currentOrganization:id,name,slug');

        if ($role) {
            $query->where('role', $role);
        }
        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->orderByDesc('created_at')->paginate($limit);

        return $this->ok([
            'items' => $users->items(),
            'pagination' => [
                'total' => $users->total(),
                'per_page' => $users->perPage(),
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
            ],
        ], 'Users list');
    }

    public function showUser(int $userId): JsonResponse
    {
        $user = User::query()
            ->with([
                'currentOrganization:id,name,slug,status',
                'organizations' => function ($query) {
                    $query->with([
                        'entitlements.app:id,name,slug',
                        'entitlements.plan:id,name,slug,type,price',
                        'subscriptions.app:id,name,slug',
                        'subscriptions.plan:id,name,slug,type,price',
                    ]);
                },
            ])
            ->find($userId);
        if (!$user) {
            return $this->fail('User not found', ['code' => 'NOT_FOUND'], 404);
        }

        $productPurchases = ProductPurchase::query()
            ->with(['product:id,slug,name,category', 'user:id,name,email'])
            ->where('user_id', $user->id)
            ->orderByDesc('created_at')
            ->get();

        return $this->ok([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'created_at' => $user->created_at,
                'current_organization' => $user->currentOrganization ? [
                    'id' => (int) $user->currentOrganization->id,
                    'name' => (string) $user->currentOrganization->name,
                    'slug' => (string) $user->currentOrganization->slug,
                    'status' => (string) $user->currentOrganization->status,
                ] : null,
                'organizations' => $user->organizations->map(fn($o) => [
                    'id' => $o->id,
                    'name' => $o->name,
                    'slug' => $o->slug,
                    'role' => $o->pivot->role ?? 'member',
                    'status' => $o->status,
                    'entitlements' => $o->entitlements->map(fn ($entitlement) => [
                        'id' => (int) $entitlement->id,
                        'status' => (string) $entitlement->status,
                        'starts_at' => $entitlement->starts_at,
                        'ends_at' => $entitlement->ends_at,
                        'app' => $entitlement->app ? [
                            'id' => (int) $entitlement->app->id,
                            'name' => (string) $entitlement->app->name,
                            'slug' => (string) $entitlement->app->slug,
                        ] : null,
                        'plan' => $entitlement->plan ? [
                            'id' => (int) $entitlement->plan->id,
                            'name' => (string) $entitlement->plan->name,
                            'slug' => (string) $entitlement->plan->slug,
                            'type' => (string) $entitlement->plan->type,
                            'price' => (int) $entitlement->plan->price,
                        ] : null,
                    ])->values(),
                    'subscriptions' => $o->subscriptions->map(fn ($subscription) => [
                        'id' => (int) $subscription->id,
                        'status' => (string) $subscription->status,
                        'billing_cycle' => (string) $subscription->billing_cycle,
                        'amount' => (int) $subscription->amount,
                        'currency' => (string) $subscription->currency,
                        'starts_at' => $subscription->starts_at,
                        'ends_at' => $subscription->ends_at,
                        'created_at' => $subscription->created_at,
                        'app' => $subscription->app ? [
                            'id' => (int) $subscription->app->id,
                            'name' => (string) $subscription->app->name,
                            'slug' => (string) $subscription->app->slug,
                        ] : null,
                        'plan' => $subscription->plan ? [
                            'id' => (int) $subscription->plan->id,
                            'name' => (string) $subscription->plan->name,
                            'slug' => (string) $subscription->plan->slug,
                            'type' => (string) $subscription->plan->type,
                            'price' => (int) $subscription->plan->price,
                        ] : null,
                    ])->values(),
                ]),
                'product_purchases' => $productPurchases->map(fn (ProductPurchase $purchase) => [
                    'id' => (int) $purchase->id,
                    'transaction_code' => (string) ($purchase->transaction_code ?? ''),
                    'amount_paid' => (int) $purchase->amount_paid,
                    'payment_status' => (string) $purchase->payment_status,
                    'payment_method' => (string) ($purchase->payment_method ?? ''),
                    'payment_gateway' => (string) ($purchase->payment_gateway ?? ''),
                    'gateway_ref' => (string) ($purchase->gateway_ref ?? ''),
                    'checkout_url' => (string) ($purchase->checkout_url ?? ''),
                    'paid_at' => $purchase->paid_at,
                    'created_at' => $purchase->created_at,
                    'product' => $purchase->product ? [
                        'id' => (int) $purchase->product->id,
                        'slug' => (string) $purchase->product->slug,
                        'name' => (string) $purchase->product->name,
                        'category' => (string) $purchase->product->category,
                    ] : null,
                ])->values(),
            ],
        ], 'User detail');
    }

    public function suspendUser(Request $request, int $userId): JsonResponse
    {
        $user = User::query()->find($userId);
        if (!$user) {
            return $this->fail('User not found', ['code' => 'NOT_FOUND'], 404);
        }

        $user->update(['role' => 'suspended']);

        $this->audit($request, 'user.suspend', 'User', $userId);

        return $this->ok(['id' => $user->id, 'role' => 'suspended'], __('hellom.user_suspended'));
    }

    public function reactivateUser(Request $request, int $userId): JsonResponse
    {
        $user = User::query()->find($userId);
        if (!$user) {
            return $this->fail('User not found', ['code' => 'NOT_FOUND'], 404);
        }

        $user->update(['role' => 'member']);

        $this->audit($request, 'user.reactivate', 'User', $userId);

        return $this->ok(['id' => $user->id, 'role' => 'member'], __('hellom.user_reactivated'));
    }

    public function deleteUser(Request $request, int $userId): JsonResponse
    {
        $user = User::query()->find($userId);
        if (!$user instanceof User) {
            return $this->fail('User not found', ['code' => 'NOT_FOUND'], 404);
        }

        DB::transaction(function () use ($user) {
            $user->organizations()->detach();
            $user->delete();
        });

        $this->audit($request, 'user.delete', 'User', $userId);

        return $this->ok(['id' => $userId], 'User deleted');
    }

    public function updateUserAppAccess(Request $request, int $userId): JsonResponse
    {
        $user = User::query()->with('organizations')->find($userId);
        if (!$user instanceof User) {
            return $this->fail('User not found', ['code' => 'NOT_FOUND'], 404);
        }

        $validated = $request->validate([
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'app_slug' => ['required', 'string'],
            'status' => ['required', 'in:active,locked,expired,cancelled,suspended'],
            'plan_id' => ['nullable', 'integer', 'exists:plans,id'],
            'subscription_status' => ['nullable', 'in:draft,pending_payment,active,failed,cancelled,expired,suspended'],
            'amount' => ['nullable', 'integer', 'min:0'],
            'billing_cycle' => ['nullable', 'in:monthly,yearly,lifetime'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
        ]);

        $organizationId = (int) $validated['organization_id'];
        $hasMembership = $user->organizations->contains(fn ($organization) => (int) $organization->id === $organizationId);
        if (!$hasMembership) {
            return $this->fail('User is not a member of the selected organization', ['code' => 'USER_NOT_IN_ORGANIZATION'], 422);
        }

        $app = AppCatalog::query()->where('slug', (string) $validated['app_slug'])->first();
        if (!$app instanceof AppCatalog) {
            return $this->fail('App not found', ['code' => 'APP_NOT_FOUND'], 404);
        }

        $entitlement = DB::transaction(function () use ($validated, $organizationId, $app) {
            $startsAt = !empty($validated['starts_at']) ? now()->parse((string) $validated['starts_at']) : null;
            $endsAt = !empty($validated['ends_at']) ? now()->parse((string) $validated['ends_at']) : null;

            $entitlement = Entitlement::query()->updateOrCreate(
                [
                    'organization_id' => $organizationId,
                    'app_id' => (int) $app->id,
                ],
                [
                    'plan_id' => $validated['plan_id'] ?? null,
                    'status' => (string) $validated['status'],
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                ]
            );

            $subscription = Subscription::query()
                ->where('organization_id', $organizationId)
                ->where('app_id', (int) $app->id)
                ->latest('id')
                ->first();

            if ($subscription instanceof Subscription) {
                $subscription->forceFill([
                    'plan_id' => $validated['plan_id'] ?? $subscription->plan_id,
                    'status' => (string) ($validated['subscription_status'] ?? $validated['status']),
                    'amount' => (int) ($validated['amount'] ?? $subscription->amount),
                    'billing_cycle' => (string) ($validated['billing_cycle'] ?? $subscription->billing_cycle),
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                ])->save();
            } elseif (!empty($validated['plan_id'])) {
                Subscription::query()->create([
                    'organization_id' => $organizationId,
                    'app_id' => (int) $app->id,
                    'plan_id' => (int) $validated['plan_id'],
                    'status' => (string) ($validated['subscription_status'] ?? $validated['status']),
                    'amount' => (int) ($validated['amount'] ?? 0),
                    'currency' => 'IDR',
                    'billing_cycle' => (string) ($validated['billing_cycle'] ?? 'monthly'),
                    'starts_at' => $startsAt,
                    'ends_at' => $endsAt,
                    'metadata' => [
                        'updated_by_super_admin' => true,
                    ],
                ]);
            }

            return $entitlement->fresh(['app', 'plan']);
        });

        if ($app->slug === 'pos' && in_array((string) $validated['status'], ['active', 'trialing'], true)) {
            app(PosProvisioningService::class)->ensureProvisionedForPos($organizationId);
        }

        $this->audit($request, 'user.app_access.update', 'Entitlement', (int) $entitlement->id, null, $validated);

        return $this->ok([
            'entitlement' => $entitlement,
        ], 'User app access updated');
    }

    // ─── App Catalog Management ───

    public function listApps(): JsonResponse
    {
        $apps = AppCatalog::query()
            ->withCount(['entitlements as active_count' => fn($q) => $q->where('status', 'active')])
            ->get();

        return $this->ok(['items' => $apps], 'App catalog');
    }

    public function updateApp(Request $request, int $appId): JsonResponse
    {
        $app = AppCatalog::query()->find($appId);
        if (!$app) {
            return $this->fail('App not found', ['code' => 'NOT_FOUND'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $app->update($validated);

        $this->audit($request, 'app.update', 'AppCatalog', $appId, null, $validated);

        return $this->ok($app->fresh(), __('hellom.app_updated'));
    }

    // ─── Plan Management ───

    public function listPlans(Request $request): JsonResponse
    {
        $appSlug = $request->query('app_slug');
        $type = $request->query('type');
        $visibility = $request->query('visibility');

        $query = Plan::query()->withCount('subscriptions');

        if ($appSlug) {
            $query->whereHas('entitlements.app', fn($q) => $q->where('slug', $appSlug));
        }
        if ($type) {
            $query->where('type', $type);
        }
        if ($visibility === 'visible') {
            $query->where('is_visible', true);
        } elseif ($visibility === 'hidden') {
            $query->where('is_visible', false);
        }

        $plans = $query->orderBy('sort_order')->orderBy('price')->get();

        return $this->ok(['items' => $plans], 'Plans list');
    }

    public function createPlan(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'slug' => ['required', 'string', 'max:50', 'unique:plans,slug'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', 'in:free,subscription,one_time,lifetime'],
            'price' => ['required', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:2000'],
            'features' => ['nullable', 'array'],
            'billing_cycles' => ['nullable', 'array'],
            'billing_cycles.*' => ['string', 'in:monthly,yearly'],
            'duration_days' => ['nullable', 'integer'],
            'max_outlets' => ['nullable', 'integer', 'min:1'],
            'is_visible' => ['nullable', 'boolean'],
            'is_recommended' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ]);

        $plan = Plan::query()->create($validated);

        $this->audit($request, 'plan.create', 'Plan', $plan->id, null, $validated);

        return $this->ok($plan, __('hellom.plan_created'), 201);
    }

    public function updatePlan(Request $request, int $planId): JsonResponse
    {
        $plan = Plan::query()->find($planId);
        if (!$plan) {
            return $this->fail('Plan not found', ['code' => 'NOT_FOUND'], 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'type' => ['sometimes', 'string', 'in:free,subscription,one_time,lifetime'],
            'price' => ['sometimes', 'integer', 'min:0'],
            'description' => ['nullable', 'string', 'max:2000'],
            'features' => ['nullable', 'array'],
            'billing_cycles' => ['nullable', 'array'],
            'billing_cycles.*' => ['string', 'in:monthly,yearly'],
            'duration_days' => ['nullable', 'integer'],
            'max_outlets' => ['nullable', 'integer', 'min:1'],
            'is_visible' => ['nullable', 'boolean'],
            'is_recommended' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $old = $plan->only(array_keys($validated));
        $plan->update($validated);

        $this->audit($request, 'plan.update', 'Plan', $planId, $old, $validated);

        return $this->ok($plan->fresh(), __('hellom.plan_updated'));
    }

    public function deletePlan(Request $request, int $planId): JsonResponse
    {
        $plan = Plan::query()->find($planId);
        if (!$plan) {
            return $this->fail('Plan not found', ['code' => 'NOT_FOUND'], 404);
        }

        // Check if plan has active subscriptions
        $activeSubscriptions = Subscription::query()
            ->where('plan_id', $planId)
            ->where('status', 'active')
            ->count();

        if ($activeSubscriptions > 0) {
            return $this->fail(
                'Cannot delete plan with active subscriptions',
                ['code' => 'PLAN_HAS_SUBSCRIPTIONS'],
                422
            );
        }

        $planIdOld = $plan->id;
        $plan->delete();

        $this->audit($request, 'plan.delete', 'Plan', $planIdOld, null, ['deleted_plan_id' => $planIdOld]);

        return $this->ok(null, __('hellom.plan_deleted'));
    }

    public function planSubscriptions(Request $request, int $planId): JsonResponse
    {
        $plan = Plan::query()->find($planId);
        if (!$plan) {
            return $this->fail('Plan not found', ['code' => 'NOT_FOUND'], 404);
        }

        $limit = min((int) ($request->query('limit') ?: 20), 100);
        $status = $request->query('status');

        $query = Subscription::query()
            ->with(['organization', 'app'])
            ->where('plan_id', $planId);

        if ($status) {
            $query->where('status', $status);
        }

        $subscriptions = $query->orderByDesc('created_at')
            ->paginate($limit);

        return $this->ok($subscriptions, 'Plan subscriptions');
    }

    // ─── Entitlement Override ───

    public function overrideEntitlement(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'organization_id' => ['required', 'integer', 'exists:organizations,id'],
            'app_slug' => ['required', 'string'],
            'status' => ['required', 'string', 'in:active,locked,cancelled,expired'],
        ]);

        $app = AppCatalog::query()->where('slug', $validated['app_slug'])->first();
        if (!$app) {
            return $this->fail('App not found', ['code' => 'APP_NOT_FOUND'], 404);
        }

        $status = (string) $validated['status'];
        $payload = ['status' => $status];

        if (in_array($status, ['active', 'trialing'], true)) {
            $payload['starts_at'] = now();
            $payload['ends_at'] = null;
        } elseif (in_array($status, ['cancelled', 'expired'], true)) {
            $payload['ends_at'] = now();
        } elseif ($status === 'locked') {
            $payload['starts_at'] = null;
            $payload['ends_at'] = null;
        }

        $entitlement = Entitlement::query()->updateOrCreate(
            [
                'organization_id' => $validated['organization_id'],
                'app_id' => $app->id,
            ],
            $payload
        );

        if ($app->slug === 'pos' && in_array($status, ['active', 'trialing'], true)) {
            app(PosProvisioningService::class)->ensureProvisionedForPos((int) $validated['organization_id']);
        }

        $this->audit($request, 'entitlement.override', 'Entitlement', $entitlement->id, null, $validated);

        return $this->ok([
            'entitlement' => $entitlement->fresh(),
        ], __('hellom.entitlement_overridden'));
    }

    // ─── Audit Logs ───

    public function auditLogs(Request $request): JsonResponse
    {
        $limit = min((int) ($request->query('limit') ?: 20), 100);
        $action = $request->query('action');
        $organizationId = $request->query('organization_id');

        $query = AuditLog::query()->with('user:id,name,email')->orderByDesc('created_at');

        if ($action) {
            $query->where('action', $action);
        }
        if ($organizationId) {
            $query->where('organization_id', $organizationId);
        }

        $logs = $query->paginate($limit);

        return $this->ok([
            'items' => $logs->items(),
            'pagination' => [
                'total' => $logs->total(),
                'per_page' => $logs->perPage(),
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
            ],
        ], 'Audit logs');
    }

    // ─── Helpers ───

    private function audit(
        Request $request,
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null,
    ): void {
        $user = $request->user();
        AuditLog::record(
            action: $action,
            userId: $user?->id,
            organizationId: $user?->current_organization_id,
            entityType: $entityType,
            entityId: $entityId,
            oldValues: $oldValues,
            newValues: $newValues,
            ipAddress: $request->ip(),
            userAgent: substr((string) $request->userAgent(), 0, 500),
        );
    }
}
