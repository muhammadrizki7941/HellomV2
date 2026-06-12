<?php

namespace App\Http\Controllers\Api\V1\Hellom;

use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use Database\Seeders\ProductPurchaseSettingSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OrganizationController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        if ((string) ($user->role ?? '') === 'admin') {
            $organizations = Organization::query()
                ->select('id', 'name', 'slug', 'status', 'default_locale')
                ->orderBy('name')
                ->get()
                ->map(fn(Organization $organization) => [
                    'id' => $organization->id,
                    'name' => $organization->name,
                    'slug' => $organization->slug,
                    'status' => $organization->status,
                    'default_locale' => $organization->default_locale,
                    'role' => 'admin',
                ])
                ->values();

            return $this->ok($organizations, 'Organizations');
        }

        $organizations = $user->organizations()
            ->select('organizations.id', 'organizations.name', 'organizations.slug', 'organizations.status', 'organizations.default_locale')
            ->orderBy('organizations.name')
            ->get()
            ->filter(function (Organization $organization) use ($user): bool {
                $currentOrgId = (int) ($user->current_organization_id ?? 0);

                if ($currentOrgId > 0) {
                    return (int) $organization->id === $currentOrgId;
                }

                return true;
            })
            ->take(1)
            ->map(fn(Organization $organization) => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'status' => $organization->status,
                'default_locale' => $organization->default_locale,
                'role' => (string) ($organization->pivot->role ?? 'member'),
            ])
            ->values();

        return $this->ok($organizations, 'Organizations');
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        if ((string) ($user->role ?? '') !== 'admin' && $user->organizations()->exists()) {
            return $this->fail(
                'Akun ini sudah terhubung ke organisasi lain dan tidak boleh membuat organisasi tambahan.',
                ['code' => 'USER_ALREADY_HAS_ORGANIZATION'],
                409
            );
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'default_locale' => ['nullable', 'in:id,en'],
        ]);

        $organization = DB::transaction(function () use ($validated, $user) {
            $baseSlug = Str::slug((string) $validated['name']);
            if ($baseSlug === '') {
                $baseSlug = 'org';
            }

            $slug = $baseSlug;
            $counter = 1;
            while (Organization::query()->where('slug', $slug)->exists()) {
                $counter++;
                $slug = $baseSlug . '-' . $counter;
            }

            $organization = Organization::query()->create([
                'name' => $validated['name'],
                'slug' => $slug,
                'default_locale' => (string) ($validated['default_locale'] ?? 'id'),
                'status' => 'active',
            ]);

            $this->seedDefaultEntitlements($organization);
            
            // Create default purchase settings for the new organization
            ProductPurchaseSettingSeeder::createDefaultsForOrganization($organization->id);

            $organization->users()->attach($user->id, ['role' => 'owner']);

            if ($user->current_organization_id === null) {
                $user->forceFill(['current_organization_id' => $organization->id])->save();
            }

            return $organization;
        });

        return $this->ok([
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'status' => $organization->status,
            'default_locale' => $organization->default_locale,
        ], 'Organization created', 201);
    }

    public function switch(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $validated = $request->validate([
            'organization_id' => ['required', 'integer'],
        ]);

        if ((string) ($user->role ?? '') === 'admin') {
            $organization = Organization::query()->find((int) $validated['organization_id']);
        } else {
            if ((int) ($user->current_organization_id ?? 0) !== (int) $validated['organization_id']) {
                return $this->fail(
                    'Akun ini dibatasi ke satu organisasi aktif dan tidak boleh berpindah ke organisasi lain.',
                    ['code' => 'ORG_SWITCH_DISABLED'],
                    403
                );
            }

            $organization = $user->organizations()
                ->where('organizations.id', (int) $validated['organization_id'])
                ->first();
        }

        if (!$organization) {
            return $this->fail('Organization not found in your access list', ['code' => 'ORG_NOT_ACCESSIBLE'], 404);
        }

        $user->forceFill(['current_organization_id' => $organization->id])->save();

        return $this->ok([
            'current_organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
                'status' => $organization->status,
            ],
        ], 'Organization switched');
    }

    public function current(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user instanceof User) {
            return $this->fail('Unauthorized', ['code' => 'UNAUTHORIZED'], 401);
        }

        $organization = $user->currentOrganization;
        if (!$organization) {
            return $this->ok(null, 'No current organization');
        }

        return $this->ok([
            'id' => $organization->id,
            'name' => $organization->name,
            'slug' => $organization->slug,
            'status' => $organization->status,
            'default_locale' => $organization->default_locale,
        ], 'Current organization');
    }

    public function settings(Request $request): JsonResponse
    {
        $user = $request->user();
        $org = $user->currentOrganization;

        if (!$org) {
            return $this->fail('No current organization', ['code' => 'NO_CURRENT_ORG'], 404);
        }

        // Convert logo ke base64 agar tidak ada CORS issue
        $logoBase64 = null;
        if ($org->logo_path) {
            $logoFullPath = storage_path('app/public/' . $org->logo_path);
            if (file_exists($logoFullPath)) {
                $logoContent = file_get_contents($logoFullPath);
                $logoMime = mime_content_type($logoFullPath);
                $logoBase64 = 'data:' . $logoMime . ';base64,' . base64_encode($logoContent);
            }
        }

        return $this->ok([
            'organization' => [
                'id' => $org->id,
                'name' => $org->name,
                'slug' => $org->slug,
                'logo_path' => $org->logo_path ?? null,
                'logo_url' => $org->logo_path
                    ? url('storage/' . $org->logo_path)
                    : null,
                'logo_base64' => $logoBase64,
                'banner_path' => $org->banner_path ?? null,
                'banner_url' => $org->banner_path
                    ? url('storage/' . $org->banner_path)
                    : null,
                'address' => $org->address ?? null,
                'phone' => $org->phone ?? null,
                'email' => $org->email ?? null,
                'description' => $org->description ?? null,
                'website' => $org->website ?? null,
            ]
        ], 'Settings retrieved');
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $user = $request->user();
        $org = $user->currentOrganization;

        if (!$org) {
            return $this->fail('No current organization', ['code' => 'NO_CURRENT_ORG'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'address' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:100',
            'description' => 'nullable|string|max:500',
            'website' => 'nullable|string|max:100',
            'logo' => 'nullable|image|mimes:jpg,jpeg,png|max:2048',
            'banner' => 'nullable|image|mimes:jpg,jpeg,png|max:5120',
        ]);

        // Handle logo upload
        if ($request->hasFile('logo')) {
            // Hapus logo lama jika ada
            if ($org->logo_path) {
                \Illuminate\Support\Facades\Storage::disk('public')
                    ->delete($org->logo_path);
            }
            $validated['logo_path'] = $request->file('logo')
                ->store('organizations/logos', 'public');
        }

        // Handle banner upload
        if ($request->hasFile('banner')) {
            // Hapus banner lama jika ada
            if ($org->banner_path) {
                \Illuminate\Support\Facades\Storage::disk('public')
                    ->delete($org->banner_path);
            }
            $validated['banner_path'] = $request->file('banner')
                ->store('banners', 'public');
        }

        // Hapus key 'logo' dan 'banner' dari validated (bukan nama kolom)
        unset($validated['logo']);
        unset($validated['banner']);

        // Update semua field
        $org->fill($validated)->save();

        // Reload dari DB
        $org->refresh();

        // Convert logo ke base64 agar tidak ada CORS issue
        $logoBase64 = null;
        if ($org->logo_path) {
            $logoFullPath = storage_path('app/public/' . $org->logo_path);
            if (file_exists($logoFullPath)) {
                $logoContent = file_get_contents($logoFullPath);
                $logoMime = mime_content_type($logoFullPath);
                $logoBase64 = 'data:' . $logoMime . ';base64,' . base64_encode($logoContent);
            }
        }

        return $this->ok([
            'organization' => [
                'id' => $org->id,
                'name' => $org->name,
                'address' => $org->address,
                'phone' => $org->phone,
                'email' => $org->email,
                'description' => $org->description,
                'website' => $org->website,
                'logo_path' => $org->logo_path,
                'logo_url' => $org->logo_path
                    ? url('storage/' . $org->logo_path)
                    : null,
                'logo_base64' => $logoBase64,
                'banner_path' => $org->banner_path,
                'banner_url' => $org->banner_path
                    ? url('storage/' . $org->banner_path)
                    : null,
            ]
        ], 'Pengaturan berhasil disimpan!');
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
