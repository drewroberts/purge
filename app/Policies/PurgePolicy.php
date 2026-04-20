<?php

namespace App\Policies;

use App\Enums\AllowedEmailDomain;
use App\Models\Purge;
use App\Models\User;

/**
 * Single-tenant internal hardening policy.
 *
 * All decisions go through the purge's owning account. A purge whose
 * account_id is null is treated as orphaned and invisible to everyone
 * (see README — this app is not yet multi-tenant-safe).
 */
class PurgePolicy
{
    /**
     * List access: whitelisted users who have at least one connected account.
     */
    public function viewAny(User $user): bool
    {
        return AllowedEmailDomain::isAllowed($user->email)
            && $user->accounts()->exists();
    }

    /**
     * A user can view a purge only if it is bound to one of their accounts.
     * Unassigned purges are blocked.
     */
    public function view(User $user, Purge $purge): bool
    {
        return $purge->account !== null
            && $purge->account->user_id === $user->id;
    }

    /**
     * Purges are created via server-side CSV import, never via Filament form.
     */
    public function create(User $user): bool
    {
        return false;
    }

    /**
     * Updates happen through narrow, explicit record actions (toggle save,
     * manual purge) that carry their own auth checks. No generic edits.
     */
    public function update(User $user, Purge $purge): bool
    {
        return false;
    }

    public function delete(User $user, Purge $purge): bool
    {
        return false;
    }

    public function restore(User $user, Purge $purge): bool
    {
        return false;
    }

    public function forceDelete(User $user, Purge $purge): bool
    {
        return false;
    }
}
