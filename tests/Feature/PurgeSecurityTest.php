<?php

use App\Models\Account;
use App\Models\Purge;
use App\Models\User;
use App\Services\PurgeService;
use App\Services\TwitterAccountService;
use Illuminate\Support\Facades\Artisan;

/*
|--------------------------------------------------------------------------
| Single-tenant internal hardening — security regressions
|--------------------------------------------------------------------------
|
| These tests cover the four explicit guarantees of the Option-A pass:
|   1. A user cannot view another user's purge (policy-level).
|   2. The scheduler does not process an unassigned purge by falling back
|      to a global/default account.
|   3. No purge path falls back to "account id = 1".
|   4. User-facing stats are user-scoped (not global).
|
| These tests should not be relaxed without an equivalent multi-tenant
| design (see README — Deployment Model & Tenancy).
*/

describe('PurgePolicy — cross-tenant isolation', function () {
    it('user A cannot view user B\'s purge', function () {
        $userA = User::factory()->create();
        $userB = User::factory()->create();

        $accountB = Account::factory()->for($userB)->twitter()->create();

        $purgeB = Purge::factory()->create([
            'account_id' => $accountB->id,
        ]);

        expect($userA->can('view', $purgeB))->toBeFalse();
        expect($userB->can('view', $purgeB))->toBeTrue();
    });

    it('blocks viewing an unassigned (orphan) purge for every user', function () {
        $userA = User::factory()->create();
        Account::factory()->for($userA)->twitter()->create();

        $orphan = Purge::factory()->orphan()->create();

        expect($userA->can('view', $orphan))->toBeFalse();
    });

    it('viewAny requires whitelisted email AND at least one connected account', function () {
        $whitelisted = User::factory()->create(['email' => 'someone@drewroberts.com']);
        $outside = User::factory()->create(['email' => 'someone@example.com']);

        // No accounts yet — both denied.
        expect($whitelisted->can('viewAny', Purge::class))->toBeFalse();
        expect($outside->can('viewAny', Purge::class))->toBeFalse();

        Account::factory()->for($whitelisted)->twitter()->create();
        Account::factory()->for($outside)->twitter()->create();

        expect($whitelisted->fresh()->can('viewAny', Purge::class))->toBeTrue();
        expect($outside->fresh()->can('viewAny', Purge::class))->toBeFalse();
    });
});

describe('Scheduler — no global default fallback', function () {
    it('skips an unassigned purge even when an active default-looking account exists', function () {
        // Create an account that, under the old behavior, would have been
        // treated as the id=1 default.
        $strangerAccount = Account::factory()->twitter()->create([
            'is_active' => true,
            'username' => 'drewroberts',
        ]);

        expect($strangerAccount->id)->toBe(1);

        $orphan = Purge::factory()->orphan()->create([
            'save' => false,
            'requested_at' => null,
            'posted_at' => now()->subYears(2),
        ]);

        $this->mock(TwitterAccountService::class)
            ->shouldNotReceive('deleteTweet');

        $exit = Artisan::call('purge:process');

        expect($exit)->toBe(0)
            ->and($orphan->fresh())
            ->requested_at->toBeNull()
            ->purged_at->toBeNull();
    });

    it('prefers an assigned purge over any orphan on the queue', function () {
        // Dangling orphan that predates everything — must NOT be picked.
        Purge::factory()->orphan()->create([
            'save' => false,
            'requested_at' => null,
            'posted_at' => now()->subYears(5),
        ]);

        $assigned = Purge::factory()->create([
            'save' => false,
            'requested_at' => null,
            'posted_at' => now()->subDays(3),
        ]);

        $this->mock(TwitterAccountService::class)
            ->shouldReceive('deleteTweet')
            ->once()
            ->andReturn(true);

        Artisan::call('purge:process');

        expect($assigned->fresh()->purged_at)->not->toBeNull();
    });
});

describe('PurgeService — no account id = 1 fallback anywhere', function () {
    it('getDefaultAccount never returns a stranger\'s account even when its id is 1', function () {
        $stranger = Account::factory()->twitter()->create(['is_active' => true]);
        expect($stranger->id)->toBe(1);

        $user = User::factory()->create();

        expect(app(PurgeService::class)->getDefaultAccount($user))->toBeNull();
    });

    it('processPurge does not resolve to a stranger\'s id=1 account for an orphan purge', function () {
        $stranger = Account::factory()->twitter()->create(['is_active' => true]);
        expect($stranger->id)->toBe(1);

        $user = User::factory()->create();
        // The user has no accounts — so even with an orphan purge and the
        // id=1 stranger account present, resolution must fail.

        $purge = Purge::factory()->orphan()->create([
            'save' => false,
            'requested_at' => null,
        ]);

        $twitter = Mockery::mock(TwitterAccountService::class);
        $twitter->shouldNotReceive('deleteTweet');

        $service = new PurgeService($twitter);

        expect($service->processPurge($purge, $user))->toBeFalse()
            ->and($purge->fresh()->requested_at)->toBeNull();
    });
});

describe('Stats — never global in user-facing contexts', function () {
    it('getStats scoped to a user excludes another user\'s purges', function () {
        $userA = User::factory()->create();
        $accountA = Account::factory()->for($userA)->twitter()->create();

        $userB = User::factory()->create();
        $accountB = Account::factory()->for($userB)->twitter()->create();

        Purge::factory()->count(3)->create([
            'account_id' => $accountA->id,
            'save' => false,
            'requested_at' => null,
        ]);

        Purge::factory()->count(7)->create([
            'account_id' => $accountB->id,
            'save' => false,
            'requested_at' => null,
        ]);

        $statsA = app(PurgeService::class)->getStats($userA);
        $statsB = app(PurgeService::class)->getStats($userB);

        expect($statsA['total'])->toBe(3)
            ->and($statsB['total'])->toBe(7);
    });

    it('PurgesTable stats action uses a user-scoped call, not a global one', function () {
        // Static inspection: the UI table MUST invoke getStats with a User.
        $source = file_get_contents(
            app_path('Filament/Resources/Purges/Tables/PurgesTable.php')
        );

        expect($source)
            ->toContain('getStats($user)')
            ->not->toMatch('/getStats\(\s*\)/');
    });
});
