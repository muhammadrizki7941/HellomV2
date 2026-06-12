<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\AppCatalog;
use App\Models\Category;
use App\Models\Entitlement;
use App\Models\ApiToken;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Models\OrganizationTeamInvitation;
use App\Services\NotificationService;
use App\Services\Hellom\PlatformMailService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use App\Mail\HellomPasswordResetMail;
use App\Mail\HellomWelcomeMail;

class AuthController extends BaseApiController
{
    public function __construct(
        private readonly PlatformMailService $platformMailService,
        private readonly NotificationService $notificationService,
    ) {
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = strtolower((string) $validated['email']);
        $user = User::query()->where('email', $email)->first();

        if (!$user instanceof User) {
            return $this->ok([
                'email' => $email,
                'sent' => true,
            ], 'If the account exists, reset instructions have been sent');
        }

        $token = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            [
                'token' => Hash::make($token),
                'created_at' => now(),
            ]
        );

        $delivery = $this->platformMailService->sendTo($user->email, new HellomPasswordResetMail(
            email: $user->email,
            token: $token,
            expiresInMinutes: (int) config('auth.passwords.users.expire', 60),
        ));

        return $this->ok([
            'email' => $email,
            'sent' => true,
            'email_delivery' => $delivery,
            'debug_reset_token' => app()->isLocal() ? $token : null,
        ], 'If the account exists, reset instructions have been sent');
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255'],
            'token' => ['required', 'string', 'min:10'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $email = strtolower((string) $validated['email']);

        $status = Password::broker()->reset(
            [
                'email' => $email,
                'token' => (string) $validated['token'],
                'password' => (string) $validated['password'],
            ],
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                ApiToken::query()->where('user_id', (int) $user->id)->delete();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return $this->fail('Password reset failed', [
                'code' => 'PASSWORD_RESET_FAILED',
                'status' => $status,
            ], 422);
        }

        return $this->ok([
            'email' => $email,
            'reset' => true,
        ], 'Password has been reset');
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
            'organization_name' => ['nullable', 'string', 'max:255'],
            'invite_token' => ['nullable', 'string', 'min:16', 'max:255'],
        ]);

        $invitation = null;
        if (!empty($validated['invite_token'])) {
            $invitation = OrganizationTeamInvitation::query()
                ->where('token_hash', hash('sha256', (string) $validated['invite_token']))
                ->where('status', OrganizationTeamInvitation::STATUS_PENDING)
                ->first();

            if (!$invitation instanceof OrganizationTeamInvitation) {
                return $this->fail('Invitation token invalid', ['code' => 'INVITATION_INVALID'], 422);
            }

            if ($invitation->expires_at && $invitation->expires_at->isPast()) {
                $invitation->forceFill([
                    'status' => OrganizationTeamInvitation::STATUS_EXPIRED,
                ])->save();

                return $this->fail('Invitation has expired', ['code' => 'INVITATION_EXPIRED'], 422);
            }

            if (strtolower((string) $invitation->email) !== strtolower((string) $validated['email'])) {
                return $this->fail('Email registrasi harus sama dengan email undangan', ['code' => 'INVITATION_EMAIL_MISMATCH'], 422);
            }
        }

        if (!$invitation && trim((string) ($validated['organization_name'] ?? '')) === '') {
            return $this->fail('Organization name is required', ['code' => 'ORGANIZATION_NAME_REQUIRED'], 422);
        }

        $user = DB::transaction(function () use ($validated, $invitation) {
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => strtolower((string) $validated['email']),
                'password' => Hash::make((string) $validated['password']),
                'role' => $invitation instanceof OrganizationTeamInvitation ? 'member' : 'admin',
            ]);

            if ($invitation instanceof OrganizationTeamInvitation) {
                $organization = Organization::query()->find((int) $invitation->organization_id);
                if (!$organization instanceof Organization) {
                    throw new \RuntimeException('Organization not found');
                }

                $organization->users()->attach($user->id, ['role' => (string) $invitation->role]);
                $user->forceFill(['current_organization_id' => $organization->id])->save();

                $invitation->forceFill([
                    'status' => OrganizationTeamInvitation::STATUS_ACCEPTED,
                    'accepted_at' => now(),
                    'accepted_by_user_id' => (int) $user->id,
                ])->save();

                return $user->fresh(['currentOrganization', 'organizations']);
            }

            $baseSlug = Str::slug((string) $validated['organization_name']);
            if ($baseSlug === '') {
                $baseSlug = 'org';
            }

            $slug = $baseSlug;
            $counter = 1;
            while (Organization::query()->where('slug', $slug)->exists()) {
                $counter++;
                $slug = $baseSlug . '-' . $counter;
            }

            $org = Organization::query()->create([
                'name' => $validated['organization_name'],
                'slug' => $slug,
                'default_locale' => 'id',
                'status' => 'active',
            ]);

            $this->seedDefaultEntitlements($org);
            $this->seedDefaultCategories($org);

            $org->users()->attach($user->id, ['role' => 'owner']);

            $user->forceFill(['current_organization_id' => $org->id])->save();

            return $user->fresh(['currentOrganization', 'organizations']);
        });

        [$plainToken] = $this->issueToken($user, 'hellom-web');

        $organizationName = $user->currentOrganization?->name;
        $welcomeDelivery = $this->platformMailService->sendTo($user->email, new HellomWelcomeMail(
            name: (string) $user->name,
            organizationName: $organizationName,
        ));

        // Create notification for new user registration
        $this->notificationService->createNewUserNotif($user, 'Hellom Platform');

        return $this->ok([
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'user' => $this->userPayload($user),
            'email_delivery' => $welcomeDelivery,
        ], __('hellom.registered'), 201);
    }

    private function seedDefaultCategories(Organization $org): void
    {
        $categories = [
            ['name' => 'Food', 'slug' => 'food', 'is_active' => true, 'sort_order' => 1],
            ['name' => 'Minuman', 'slug' => 'minuman', 'is_active' => true, 'sort_order' => 2],
            ['name' => 'Dessert', 'slug' => 'dessert', 'is_active' => true, 'sort_order' => 3],
        ];

        foreach ($categories as $category) {
            \App\Models\Category::create([
                'tenant_id' => $org->id,
                'name' => $category['name'],
                'slug' => $category['slug'],
                'is_active' => $category['is_active'],
                'sort_order' => $category['sort_order'],
            ]);
        }
    }

    public function ssoLogin(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'sso_token' => ['required', 'string'],
        ]);

        $token = (string) $validated['sso_token'];
        $ssoData = cache()->get("pos_sso:{$token}");

        if (!$ssoData || now()->isAfter($ssoData['expires_at'])) {
            return $this->fail('Invalid or expired SSO token', ['code' => 'INVALID_SSO_TOKEN'], 401);
        }

        $user = User::find($ssoData['user_id']);
        if (!$user) {
            return $this->fail('User not found', ['code' => 'USER_NOT_FOUND'], 404);
        }

        $organizationId = (int) ($ssoData['organization_id'] ?? 0);
        if ($organizationId <= 0) {
            return $this->fail('SSO organization is invalid', ['code' => 'INVALID_SSO_ORGANIZATION'], 422);
        }

        $organization = Organization::query()->find($organizationId);
        if (!$organization instanceof Organization || (string) $organization->status !== 'active') {
            return $this->fail('SSO organization is not active', ['code' => 'SSO_ORGANIZATION_INACTIVE'], 403);
        }

        $hasAccessToOrganization = $user->organizations()
            ->where('organizations.id', $organizationId)
            ->exists();

        if (!$hasAccessToOrganization) {
            return $this->fail('User does not have access to SSO organization', ['code' => 'SSO_ORGANIZATION_ACCESS_DENIED'], 403);
        }

        if ((int) ($user->current_organization_id ?? 0) !== $organizationId) {
            $user->forceFill([
                'current_organization_id' => $organizationId,
            ])->save();
        }

        // Invalidate token after use
        cache()->forget("pos_sso:{$token}");

        [$plainToken] = $this->issueToken($user, 'hellom-web');
        $freshUser = $user->fresh(['currentOrganization', 'organizations']);

        return $this->ok([
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'user' => $this->userPayload($freshUser instanceof User ? $freshUser : $user),
        ], 'SSO login successful');
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::query()
            ->where('email', strtolower((string) $validated['email']))
            ->first();

        if (!$user || !Hash::check((string) $validated['password'], (string) $user->password)) {
            return $this->fail(__('hellom.invalid_credentials'), ['code' => 'INVALID_CREDENTIALS'], 401);
        }

        [$plainToken] = $this->issueToken($user, 'hellom-web');

        return $this->ok([
            'token' => $plainToken,
            'token_type' => 'Bearer',
            'user' => $this->userPayload($user->fresh(['currentOrganization', 'organizations'])),
        ], __('hellom.logged_in'));
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        return $this->ok($this->userPayload($user->fresh(['currentOrganization', 'organizations'])), 'Current user');
    }

    public function logout(Request $request): JsonResponse
    {
        $apiToken = $request->attributes->get('apiToken');
        if ($apiToken instanceof ApiToken) {
            $apiToken->delete();
        }

        return $this->ok(null, __('hellom.logged_out'));
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,' . $user->id],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30'],
        ]);

        if (isset($validated['email'])) {
            $validated['email'] = strtolower((string) $validated['email']);
        }

        $user->update($validated);

        return $this->ok(
            $this->userPayload($user->fresh(['currentOrganization', 'organizations'])),
            __('hellom.profile_updated')
        );
    }

    public function changePassword(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        if (!Hash::check((string) $request->input('current_password'), (string) $user->password)) {
            return $this->fail(__('hellom.invalid_current_password'), ['code' => 'INVALID_CURRENT_PASSWORD'], 422);
        }

        $user->forceFill([
            'password' => Hash::make((string) $request->input('password')),
        ])->save();

        return $this->ok(null, __('hellom.password_changed'));
    }

    /**
     * @return array{0:string,1:ApiToken}
     */
    private function issueToken(User $user, string $name): array
    {
        $plain = Str::random(64);

        $token = ApiToken::query()->create([
            'user_id' => $user->id,
            'name' => $name,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays(30),
        ]);

        return [$plain, $token];
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'current_organization' => $user->currentOrganization ? [
                'id' => $user->currentOrganization->id,
                'name' => $user->currentOrganization->name,
                'slug' => $user->currentOrganization->slug,
                'status' => $user->currentOrganization->status,
            ] : null,
            'organizations' => $user->organizations->map(fn(Organization $organization) => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'status' => $organization->status,
                'role' => (string) ($organization->pivot->role ?? 'member'),
            ])->values()->all(),
        ];
    }

    private function seedDefaultEntitlements(Organization $organization): void
    {
        $landingAppId = AppCatalog::query()->where('slug', 'landing_builder')->value('id');
        $posAppId = AppCatalog::query()->where('slug', 'pos')->value('id');
        $freePlanId = Plan::query()->where('slug', 'free')->value('id');
        $posPlanId = Plan::query()->where('slug', 'pos_starter')->value('id');

        if ($landingAppId) {
            Entitlement::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'app_id' => $landingAppId,
                ],
                [
                    'plan_id' => $freePlanId,
                    'status' => 'active',
                    'starts_at' => now(),
                    'ends_at' => null,
                ]
            );
        }

        if ($posAppId) {
            Entitlement::query()->updateOrCreate(
                [
                    'organization_id' => $organization->id,
                    'app_id' => $posAppId,
                ],
                [
                    'plan_id' => $posPlanId,
                    'status' => 'locked',
                    'starts_at' => null,
                    'ends_at' => null,
                ]
            );
        }
    }
}
