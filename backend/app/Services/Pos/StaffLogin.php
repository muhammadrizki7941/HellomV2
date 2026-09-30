<?php

namespace App\Services\Pos;

use App\Models\AppCatalog;
use App\Models\Entitlement;
use App\Models\Organization;
use App\Models\PosStaff;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Staff / cashier login (POST /auth/staff-login): which store(s) this account works at, and
 * entering one of them (current organization + linked staff + team membership).
 *
 * A staff record belongs to an account when it is already linked (invitation) or, only for
 * accounts with a VERIFIED email, when the owner registered the staff with that email.
 * Registration does not verify email, so an unverified account never takes over a staff
 * record by email alone.
 */
class StaffLogin
{
    /**
     * Active staff records the user may log in as, one per store (the newest record, the
     * same one InjectPosContext picks), only for active stores with an active POS subscription.
     *
     * @return Collection<int, PosStaff>
     */
    public function candidates(User $user): Collection
    {
        $email = mb_strtolower(trim((string) $user->email));

        return PosStaff::query()
            ->where('employment_status', 'active')
            ->where(function ($q) use ($user, $email) {
                $q->where('linked_user_id', $user->id);
                if ($user->email_verified_at !== null && $email !== '') {
                    $q->orWhere(fn ($w) => $w->whereNull('linked_user_id')->whereRaw('LOWER(email) = ?', [$email]));
                }
            })
            ->orderByDesc('id')
            ->get()
            ->unique('organization_id')
            ->filter(fn (PosStaff $staff) => $this->storeOpen((int) $staff->organization_id) && $staff->resolveBoundOutlet() !== null)
            ->values();
    }

    /** Staff records that match only by email while the account email is unverified. */
    public function unverifiedMatches(User $user): int
    {
        if ($user->email_verified_at !== null) {
            return 0;
        }

        return PosStaff::query()->where('employment_status', 'active')->whereNull('linked_user_id')
            ->whereRaw('LOWER(email) = ?', [mb_strtolower(trim((string) $user->email))])->count();
    }

    /** Switch the account into this staff's store; link the staff record and team membership. */
    public function enter(User $user, PosStaff $staff): void
    {
        DB::transaction(function () use ($user, $staff) {
            if ($staff->linked_user_id === null) {
                PosStaff::query()->whereKey($staff->id)->whereNull('linked_user_id')->update(['linked_user_id' => $user->id]);
            }
            $organization = Organization::query()->findOrFail((int) $staff->organization_id);
            $isMember = $organization->users()->where('users.id', $user->id)->exists();
            if (!$isMember) {
                $organization->users()->attach($user->id, ['role' => 'cashier']);
            }
            $user->forceFill(['current_organization_id' => $organization->id])->save();
        });
    }

    /** @return array{staff_id:int, organization_id:int, organization_name:string, outlet_name:?string} */
    public function choice(PosStaff $staff): array
    {
        return [
            'staff_id' => (int) $staff->id,
            'organization_id' => (int) $staff->organization_id,
            'organization_name' => (string) Organization::query()->whereKey($staff->organization_id)->value('name'),
            'outlet_name' => $staff->resolveBoundOutlet()?->name,
        ];
    }

    private function storeOpen(int $organizationId): bool
    {
        $organization = Organization::query()->find($organizationId);
        if (!$organization || (string) $organization->status !== 'active') {
            return false;
        }
        $appId = AppCatalog::query()->where('slug', 'pos')->value('id');
        $entitlement = $appId ? Entitlement::query()->where('organization_id', $organizationId)->where('app_id', $appId)
            ->orderByDesc('updated_at')->orderByDesc('id')->first() : null;

        return $entitlement instanceof Entitlement && $entitlement->allowsAccess();
    }
}
