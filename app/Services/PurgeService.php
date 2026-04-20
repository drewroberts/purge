<?php

namespace App\Services;

use App\Enums\SocialService;
use App\Models\Account;
use App\Models\Purge;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class PurgeService
{
    public function __construct(
        protected TwitterAccountService $twitterService
    ) {}

    /**
     * Return the given user's first active Twitter account, or null.
     *
     * There is no global "account id = 1" default. A user context is
     * required so we can never accidentally act on another tenant's account.
     */
    public function getDefaultAccount(User $user): ?Account
    {
        return $user->accounts()
            ->where('service', SocialService::TWITTER)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }

    /**
     * Process a single purge.
     *
     * Account resolution order:
     *   1. $purge->account — used as-is. If $user is provided, the account
     *      must belong to that user or the purge is skipped.
     *   2. $user's default Twitter account — ONLY when $user is explicitly
     *      provided by the caller (UI action or CLI with derived user).
     *   3. Otherwise: skip and log. A global default is never used.
     */
    public function processPurge(Purge $purge, ?User $user = null): bool
    {
        if ($purge->requested_at || $purge->purged_at) {
            Log::warning('Purge already processed', ['purge_id' => $purge->id]);

            return false;
        }

        if ($purge->save) {
            Log::info('Purge skipped - marked as saved', ['purge_id' => $purge->id]);

            return false;
        }

        $account = $this->resolveAccount($purge, $user);

        if (! $account) {
            Log::warning('Purge skipped - no account resolvable', [
                'purge_id' => $purge->id,
                'purge_has_account' => $purge->account_id !== null,
                'user_provided' => $user !== null,
            ]);

            return false;
        }

        // Mark as requested before attempting (at-most-once semantics).
        $purge->update(['requested_at' => now()]);

        try {
            $deleted = $this->twitterService->deleteTweet($purge, $account);

            if ($deleted) {
                $purge->update(['purged_at' => now()]);

                Log::info('Tweet purged successfully', [
                    'purge_id' => $purge->id,
                    'post_id' => $purge->post_id,
                    'account' => $account->username,
                ]);

                return true;
            }

            Log::warning('Failed to delete tweet', [
                'purge_id' => $purge->id,
                'post_id' => $purge->post_id,
            ]);

            return false;
        } catch (\Exception $e) {
            Log::error('Error processing purge', [
                'purge_id' => $purge->id,
                'post_id' => $purge->post_id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    protected function resolveAccount(Purge $purge, ?User $user): ?Account
    {
        if ($purge->account) {
            if ($user !== null && $purge->account->user_id !== $user->id) {
                Log::warning('Purge account does not belong to provided user', [
                    'purge_id' => $purge->id,
                    'account_user_id' => $purge->account->user_id,
                    'provided_user_id' => $user->id,
                ]);

                return null;
            }

            return $purge->account;
        }

        // Purge is unassigned. Only use the user's default when a user was
        // explicitly supplied — never a silent global fallback.
        if ($user === null) {
            return null;
        }

        return $this->getDefaultAccount($user);
    }

    /**
     * Next pending purge that has an assigned account (so a user is
     * derivable). Unassigned purges are intentionally invisible to the
     * scheduler.
     */
    public function getNextPendingPurge(): ?Purge
    {
        return Purge::pending()
            ->whereNotNull('account_id')
            ->orderBy('posted_at', 'asc')
            ->first();
    }

    /**
     * Purge statistics.
     *
     * Pass $user (or $account) for tenant-scoped stats. UI code MUST pass
     * one of these — global counts must never be shown on user-facing
     * screens. Calling with neither returns global counts and is reserved
     * for internal/CLI use.
     *
     * @return array{total: int, pending: int, requested: int, purged: int, saved: int}
     */
    public function getStats(?User $user = null, ?Account $account = null): array
    {
        $base = Purge::query();

        if ($account !== null) {
            $base->where('account_id', $account->id);
        } elseif ($user !== null) {
            $userAccountIds = $user->accounts()->pluck('id');
            $base->whereIn('account_id', $userAccountIds);
        }

        return [
            'total' => (clone $base)->count(),
            'pending' => (clone $base)->where('save', false)->whereNull('requested_at')->count(),
            'requested' => (clone $base)->whereNotNull('requested_at')->whereNull('purged_at')->count(),
            'purged' => (clone $base)->whereNotNull('purged_at')->count(),
            'saved' => (clone $base)->where('save', true)->count(),
        ];
    }
}
