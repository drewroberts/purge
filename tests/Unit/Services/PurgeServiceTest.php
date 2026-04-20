<?php

use App\Models\Account;
use App\Models\Purge;
use App\Models\User;
use App\Services\PurgeService;
use App\Services\TwitterAccountService;
use Illuminate\Support\Facades\Log;

describe('PurgeService - Default Account', function () {
    it('returns the user\'s first active Twitter account', function () {
        $user = User::factory()->create();

        $expected = Account::factory()->for($user)->twitter()->create([
            'is_active' => true,
        ]);

        Account::factory()->for($user)->twitter()->create([
            'is_active' => true,
        ]);

        $service = app(PurgeService::class);

        expect($service->getDefaultAccount($user))
            ->toBeInstanceOf(Account::class)
            ->id->toBe($expected->id);
    });

    it('returns null when the user has no connected accounts', function () {
        $user = User::factory()->create();

        $service = app(PurgeService::class);

        expect($service->getDefaultAccount($user))->toBeNull();
    });

    it('ignores inactive Twitter accounts', function () {
        $user = User::factory()->create();

        Account::factory()->for($user)->twitter()->create([
            'is_active' => false,
        ]);

        $service = app(PurgeService::class);

        expect($service->getDefaultAccount($user))->toBeNull();
    });

    it('ignores non-Twitter accounts', function () {
        $user = User::factory()->create();

        Account::factory()->for($user)->facebook()->create([
            'is_active' => true,
        ]);

        $service = app(PurgeService::class);

        expect($service->getDefaultAccount($user))->toBeNull();
    });

    it('never falls back to a global account id = 1', function () {
        // A "legacy" account owned by someone else that previously would have
        // been returned by the old hardcoded lookup.
        $strangerAccount = Account::factory()->twitter()->create([
            'is_active' => true,
        ]);
        // Force the stranger's account to id = 1 to mirror the old default.
        // (First created account in a fresh DB already gets id = 1, but we
        // assert explicitly.)
        expect($strangerAccount->id)->toBe(1);

        $user = User::factory()->create();

        $service = app(PurgeService::class);

        expect($service->getDefaultAccount($user))->toBeNull();
    });
});

describe('PurgeService - Next Pending Purge', function () {
    it('returns oldest pending purge with an assigned account', function () {
        $oldest = Purge::factory()->create([
            'posted_at' => now()->subDays(10),
            'save' => false,
            'requested_at' => null,
        ]);

        Purge::factory()->create([
            'posted_at' => now()->subDays(5),
            'save' => false,
            'requested_at' => null,
        ]);

        expect(app(PurgeService::class)->getNextPendingPurge()->id)->toBe($oldest->id);
    });

    it('excludes saved purges', function () {
        Purge::factory()->create([
            'posted_at' => now()->subDays(10),
            'save' => true,
        ]);

        $expected = Purge::factory()->create([
            'posted_at' => now()->subDays(5),
            'save' => false,
            'requested_at' => null,
        ]);

        expect(app(PurgeService::class)->getNextPendingPurge()->id)->toBe($expected->id);
    });

    it('excludes already requested purges', function () {
        Purge::factory()->create([
            'posted_at' => now()->subDays(10),
            'save' => false,
            'requested_at' => now()->subHour(),
        ]);

        $expected = Purge::factory()->create([
            'posted_at' => now()->subDays(5),
            'save' => false,
            'requested_at' => null,
        ]);

        expect(app(PurgeService::class)->getNextPendingPurge()->id)->toBe($expected->id);
    });

    it('excludes unassigned purges (no account)', function () {
        Purge::factory()->orphan()->create([
            'posted_at' => now()->subDays(30),
            'save' => false,
            'requested_at' => null,
        ]);

        $assigned = Purge::factory()->create([
            'posted_at' => now()->subDays(5),
            'save' => false,
            'requested_at' => null,
        ]);

        expect(app(PurgeService::class)->getNextPendingPurge()->id)->toBe($assigned->id);
    });

    it('returns null when nothing is eligible', function () {
        Purge::factory()->create([
            'save' => true,
            'requested_at' => null,
        ]);

        Purge::factory()->create([
            'save' => false,
            'requested_at' => now(),
        ]);

        expect(app(PurgeService::class)->getNextPendingPurge())->toBeNull();
    });
});

describe('PurgeService - Statistics', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->account = Account::factory()->for($this->user)->twitter()->create();

        Purge::factory()->count(3)->create([
            'account_id' => $this->account->id,
            'save' => false,
            'requested_at' => null,
            'purged_at' => null,
        ]);

        Purge::factory()->count(2)->create([
            'account_id' => $this->account->id,
            'save' => true,
            'requested_at' => null,
            'purged_at' => null,
        ]);

        Purge::factory()->count(4)->create([
            'account_id' => $this->account->id,
            'save' => false,
            'requested_at' => now()->subHour(),
            'purged_at' => null,
        ]);

        Purge::factory()->count(5)->create([
            'account_id' => $this->account->id,
            'save' => false,
            'requested_at' => now()->subDay(),
            'purged_at' => now()->subHours(12),
        ]);
    });

    it('scopes stats to the supplied user', function () {
        $stats = app(PurgeService::class)->getStats($this->user);

        expect($stats)->toMatchArray([
            'total' => 14,
            'pending' => 3,
            'saved' => 2,
            'requested' => 4,
            'purged' => 5,
        ]);
    });

    it('scopes stats to a specific account', function () {
        $stats = app(PurgeService::class)->getStats(null, $this->account);

        expect($stats['total'])->toBe(14);
    });

    it('excludes purges owned by other users when scoped', function () {
        $otherUser = User::factory()->create();
        $otherAccount = Account::factory()->for($otherUser)->twitter()->create();

        Purge::factory()->count(7)->create([
            'account_id' => $otherAccount->id,
            'save' => false,
            'requested_at' => null,
        ]);

        $stats = app(PurgeService::class)->getStats($this->user);

        expect($stats['total'])->toBe(14);
    });
});

describe('PurgeService - Process Purge', function () {
    beforeEach(function () {
        $this->user = User::factory()->create();
        $this->account = Account::factory()->for($this->user)->twitter()->create();
    });

    it('marks purge as requested before attempting deletion', function () {
        $purge = Purge::factory()->create([
            'account_id' => $this->account->id,
            'save' => false,
            'requested_at' => null,
            'purged_at' => null,
        ]);

        $twitterService = Mockery::mock(TwitterAccountService::class);
        $twitterService->shouldReceive('deleteTweet')->once()->andReturn(true);

        $service = new PurgeService($twitterService);
        $service->processPurge($purge, $this->user);

        expect($purge->fresh()->requested_at)->not->toBeNull();
    });

    it('marks purge as purged on successful deletion', function () {
        $purge = Purge::factory()->create([
            'account_id' => $this->account->id,
            'save' => false,
            'requested_at' => null,
            'purged_at' => null,
        ]);

        $twitterService = Mockery::mock(TwitterAccountService::class);
        $twitterService->shouldReceive('deleteTweet')->once()->andReturn(true);

        $service = new PurgeService($twitterService);
        $result = $service->processPurge($purge, $this->user);

        expect($result)->toBeTrue()
            ->and($purge->fresh()->purged_at)->not->toBeNull();
    });

    it('returns false for already requested purge', function () {
        $purge = Purge::factory()->create([
            'account_id' => $this->account->id,
            'save' => false,
            'requested_at' => now()->subHour(),
            'purged_at' => null,
        ]);

        $twitterService = Mockery::mock(TwitterAccountService::class);
        $twitterService->shouldNotReceive('deleteTweet');

        Log::shouldReceive('warning')->once();

        $service = new PurgeService($twitterService);

        expect($service->processPurge($purge, $this->user))->toBeFalse();
    });

    it('returns false for already purged record', function () {
        $purge = Purge::factory()->create([
            'account_id' => $this->account->id,
            'save' => false,
            'requested_at' => now()->subHour(),
            'purged_at' => now()->subMinutes(30),
        ]);

        $twitterService = Mockery::mock(TwitterAccountService::class);
        $twitterService->shouldNotReceive('deleteTweet');

        Log::shouldReceive('warning')->once();

        $service = new PurgeService($twitterService);

        expect($service->processPurge($purge, $this->user))->toBeFalse();
    });

    it('skips saved purges', function () {
        $purge = Purge::factory()->create([
            'account_id' => $this->account->id,
            'save' => true,
            'requested_at' => null,
            'purged_at' => null,
        ]);

        $twitterService = Mockery::mock(TwitterAccountService::class);
        $twitterService->shouldNotReceive('deleteTweet');

        Log::shouldReceive('info')->once();

        $service = new PurgeService($twitterService);

        expect($service->processPurge($purge, $this->user))->toBeFalse()
            ->and($purge->fresh()->requested_at)->toBeNull();
    });

    it('uses the purge-bound account when present', function () {
        $purge = Purge::factory()->create([
            'account_id' => $this->account->id,
            'save' => false,
            'requested_at' => null,
        ]);

        $twitterService = Mockery::mock(TwitterAccountService::class);
        $twitterService->shouldReceive('deleteTweet')->once()->andReturn(true);

        $service = new PurgeService($twitterService);

        expect($service->processPurge($purge, $this->user))->toBeTrue();
    });

    it('uses the user\'s default account when purge is unassigned AND user is provided', function () {
        $purge = Purge::factory()->orphan()->create([
            'save' => false,
            'requested_at' => null,
        ]);

        $twitterService = Mockery::mock(TwitterAccountService::class);
        $twitterService->shouldReceive('deleteTweet')->once()->andReturn(true);

        $service = new PurgeService($twitterService);

        expect($service->processPurge($purge, $this->user))->toBeTrue();
    });

    it('skips when purge is unassigned AND no user is provided', function () {
        $purge = Purge::factory()->orphan()->create([
            'save' => false,
            'requested_at' => null,
        ]);

        $twitterService = Mockery::mock(TwitterAccountService::class);
        $twitterService->shouldNotReceive('deleteTweet');

        Log::shouldReceive('warning')->once();

        $service = new PurgeService($twitterService);

        expect($service->processPurge($purge))->toBeFalse()
            ->and($purge->fresh()->requested_at)->toBeNull();
    });

    it('skips when the provided user does not own the purge\'s account', function () {
        $otherUser = User::factory()->create();
        $otherAccount = Account::factory()->for($otherUser)->twitter()->create();

        $purge = Purge::factory()->create([
            'account_id' => $otherAccount->id,
            'save' => false,
            'requested_at' => null,
        ]);

        $twitterService = Mockery::mock(TwitterAccountService::class);
        $twitterService->shouldNotReceive('deleteTweet');

        // Two warnings: the ownership mismatch (specific) plus the
        // "no account resolvable" catch-all that follows.
        Log::shouldReceive('warning')->twice();

        $service = new PurgeService($twitterService);

        expect($service->processPurge($purge, $this->user))->toBeFalse()
            ->and($purge->fresh()->requested_at)->toBeNull();
    });

    it('skips when purge is unassigned and the user has no accounts', function () {
        $lonelyUser = User::factory()->create();

        $purge = Purge::factory()->orphan()->create([
            'save' => false,
            'requested_at' => null,
        ]);

        $twitterService = Mockery::mock(TwitterAccountService::class);
        $twitterService->shouldNotReceive('deleteTweet');

        Log::shouldReceive('warning')->once();

        $service = new PurgeService($twitterService);

        expect($service->processPurge($purge, $lonelyUser))->toBeFalse()
            ->and($purge->fresh()->requested_at)->toBeNull();
    });

    it('handles deletion failure gracefully', function () {
        $purge = Purge::factory()->create([
            'account_id' => $this->account->id,
            'save' => false,
            'requested_at' => null,
            'purged_at' => null,
        ]);

        $twitterService = Mockery::mock(TwitterAccountService::class);
        $twitterService->shouldReceive('deleteTweet')->once()->andReturn(false);

        Log::shouldReceive('warning')->once();

        $service = new PurgeService($twitterService);

        expect($service->processPurge($purge, $this->user))->toBeFalse()
            ->and($purge->fresh()->purged_at)->toBeNull();
    });

    it('handles exceptions during deletion', function () {
        $purge = Purge::factory()->create([
            'account_id' => $this->account->id,
            'save' => false,
            'requested_at' => null,
            'purged_at' => null,
        ]);

        $twitterService = Mockery::mock(TwitterAccountService::class);
        $twitterService->shouldReceive('deleteTweet')->once()->andThrow(new Exception('API Error'));

        Log::shouldReceive('error')->once();

        $service = new PurgeService($twitterService);

        expect($service->processPurge($purge, $this->user))->toBeFalse();
    });
});
