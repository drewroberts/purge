<?php

use App\Enums\SocialService;
use App\Models\Account;
use App\Models\Purge;
use App\Services\TwitterAccountService;
use Illuminate\Support\Facades\Artisan;

describe('Process Purge Queue Command', function () {
    beforeEach(function () {
        $this->account = Account::factory()->create([
            'service' => SocialService::TWITTER,
            'username' => 'drewroberts',
            'is_active' => true,
        ]);
    });

    it('processes next pending purge', function () {
        $purge = Purge::factory()->create([
            'posted_at' => now()->subDays(10),
            'save' => false,
            'requested_at' => null,
            'purged_at' => null,
        ]);

        $this->mock(TwitterAccountService::class)
            ->shouldReceive('deleteTweet')
            ->once()
            ->andReturn(true);

        Artisan::call('purge:process');

        expect($purge->fresh())
            ->requested_at->not->toBeNull()
            ->purged_at->not->toBeNull();
    });

    it('returns success when purge processed', function () {
        Purge::factory()->create([
            'save' => false,
            'requested_at' => null,
            'purged_at' => null,
        ]);

        $this->mock(TwitterAccountService::class)
            ->shouldReceive('deleteTweet')
            ->once()
            ->andReturn(true);

        $exitCode = Artisan::call('purge:process');

        expect($exitCode)->toBe(0);
    });

    it('returns success when no pending purges', function () {
        Purge::factory()->create([
            'save' => true,
            'requested_at' => null,
            'purged_at' => null,
        ]);

        $exitCode = Artisan::call('purge:process');

        expect($exitCode)->toBe(0);
    });

    it('processes oldest tweet first', function () {
        $oldest = Purge::factory()->create([
            'posted_at' => now()->subDays(30),
            'save' => false,
            'requested_at' => null,
        ]);

        $newer = Purge::factory()->create([
            'posted_at' => now()->subDays(5),
            'save' => false,
            'requested_at' => null,
        ]);

        $this->mock(TwitterAccountService::class)
            ->shouldReceive('deleteTweet')
            ->once()
            ->andReturn(true);

        Artisan::call('purge:process');

        expect($oldest->fresh()->requested_at)->not->toBeNull()
            ->and($newer->fresh()->requested_at)->toBeNull();
    });

    it('skips saved tweets', function () {
        $saved = Purge::factory()->create([
            'posted_at' => now()->subDays(30),
            'save' => true,
            'requested_at' => null,
        ]);

        $pending = Purge::factory()->create([
            'posted_at' => now()->subDays(5),
            'save' => false,
            'requested_at' => null,
        ]);

        $this->mock(TwitterAccountService::class)
            ->shouldReceive('deleteTweet')
            ->once()
            ->andReturn(true);

        Artisan::call('purge:process');

        expect($saved->fresh()->requested_at)->toBeNull()
            ->and($pending->fresh()->requested_at)->not->toBeNull();
    });

    it('skips already requested tweets', function () {
        $requested = Purge::factory()->create([
            'posted_at' => now()->subDays(30),
            'save' => false,
            'requested_at' => now()->subHour(),
            'purged_at' => null,
        ]);

        $pending = Purge::factory()->create([
            'posted_at' => now()->subDays(5),
            'save' => false,
            'requested_at' => null,
        ]);

        $this->mock(TwitterAccountService::class)
            ->shouldReceive('deleteTweet')
            ->once()
            ->andReturn(true);

        Artisan::call('purge:process');

        expect($pending->fresh()->purged_at)->not->toBeNull();
    });

    it('handles processing failure gracefully', function () {
        $purge = Purge::factory()->create([
            'save' => false,
            'requested_at' => null,
            'purged_at' => null,
        ]);

        $this->mock(TwitterAccountService::class)
            ->shouldReceive('deleteTweet')
            ->once()
            ->andReturn(false);

        $exitCode = Artisan::call('purge:process');

        expect($exitCode)->toBe(1)
            ->and($purge->fresh()->requested_at)->not->toBeNull()
            ->and($purge->fresh()->purged_at)->toBeNull();
    });
});

describe('CSV Import and Purge Workflow', function () {
    it('creates purge records with unique post_ids', function () {
        $data = [
            ['post_id' => '1234567890', 'posted_at' => '2023-01-01 12:00:00', 'text' => 'First tweet'],
            ['post_id' => '0987654321', 'posted_at' => '2023-01-02 12:00:00', 'text' => 'Second tweet'],
            ['post_id' => '1111111111', 'posted_at' => '2023-01-03 12:00:00', 'text' => 'Third tweet'],
        ];

        foreach ($data as $row) {
            Purge::create($row);
        }

        expect(Purge::count())->toBe(3);
    });

    it('prevents duplicate post_ids', function () {
        Purge::create([
            'post_id' => '1234567890',
            'posted_at' => '2023-01-01 12:00:00',
            'text' => 'First tweet',
        ]);

        expect(fn () => Purge::create([
            'post_id' => '1234567890',
            'posted_at' => '2023-01-02 12:00:00',
            'text' => 'Duplicate tweet',
        ]))->toThrow(\Illuminate\Database\QueryException::class);
    });

    it('creates purges with default pending status', function () {
        $purge = Purge::create([
            'post_id' => '1234567890',
            'posted_at' => '2023-01-01 12:00:00',
            'text' => 'Test tweet',
        ]);

        expect($purge->status)->toBe('Pending')
            ->and($purge->fresh()->save)->toBe(false)
            ->and($purge->requested_at)->toBeNull()
            ->and($purge->purged_at)->toBeNull();
    });

    it('allows marking tweets as saved during import', function () {
        $purge = Purge::create([
            'post_id' => '1234567890',
            'posted_at' => '2023-01-01 12:00:00',
            'text' => 'Important tweet',
            'save' => true,
        ]);

        expect($purge->status)->toBe('Saved');
    });

    it('associates purge with specific account', function () {
        $account = Account::factory()->create([
            'service' => SocialService::TWITTER,
        ]);

        $purge = Purge::create([
            'post_id' => '1234567890',
            'posted_at' => '2023-01-01 12:00:00',
            'text' => 'Test tweet',
            'account_id' => $account->id,
        ]);

        expect($purge->account->id)->toBe($account->id);
    });
});

describe('Purge Lifecycle', function () {
    it('transitions from Pending to Requested to Purged', function () {
        $purge = Purge::factory()->create([
            'save' => false,
            'requested_at' => null,
            'purged_at' => null,
        ]);

        expect($purge->status)->toBe('Pending');

        $purge->update(['requested_at' => now()]);
        expect($purge->fresh()->status)->toBe('Requested');

        $purge->update(['purged_at' => now()]);
        expect($purge->fresh()->status)->toBe('Purged');
    });

    it('can be saved at any point in lifecycle', function () {
        $purge = Purge::factory()->create([
            'save' => false,
            'requested_at' => now()->subHour(),
            'purged_at' => null,
        ]);

        expect($purge->status)->toBe('Requested');

        $purge->update(['save' => true]);
        expect($purge->fresh()->status)->toBe('Saved');
    });

    it('maintains save status even after purging', function () {
        $purge = Purge::factory()->create([
            'save' => true,
            'requested_at' => now()->subHour(),
            'purged_at' => now(),
        ]);

        expect($purge->status)->toBe('Saved');
    });
});

describe('Bulk Purge Operations', function () {
    it('processes multiple purges in order', function () {
        Account::factory()->create([
            'service' => SocialService::TWITTER,
            'username' => 'drewroberts',
            'is_active' => true,
        ]);

        $purges = Purge::factory()->count(5)->create([
            'save' => false,
            'requested_at' => null,
            'purged_at' => null,
        ])->sortBy('posted_at');

        $this->mock(TwitterAccountService::class)
            ->shouldReceive('deleteTweet')
            ->times(5)
            ->andReturn(true);

        foreach ($purges as $purge) {
            Artisan::call('purge:process');
        }

        expect(Purge::pending()->count())->toBe(0)
            ->and(Purge::purged()->count())->toBe(5);
    });

    it('counts pending purges correctly during processing', function () {
        Purge::factory()->count(10)->create([
            'save' => false,
            'requested_at' => null,
            'purged_at' => null,
        ]);

        Purge::factory()->count(3)->create([
            'save' => true,
            'requested_at' => null,
        ]);

        expect(Purge::pending()->count())->toBe(10)
            ->and(Purge::where('save', true)->count())->toBe(3)
            ->and(Purge::count())->toBe(13);
    });
});

describe('Account Integration', function () {
    it('uses correct account for purge processing', function () {
        $account1 = Account::factory()->create([
            'service' => SocialService::TWITTER,
            'username' => 'drewroberts',
            'is_active' => true,
        ]);

        $account2 = Account::factory()->create([
            'service' => SocialService::TWITTER,
            'username' => 'other',
            'is_active' => true,
        ]);

        $purge1 = Purge::factory()->create([
            'account_id' => $account1->id,
            'save' => false,
            'requested_at' => null,
        ]);

        $purge2 = Purge::factory()->create([
            'account_id' => $account2->id,
            'save' => false,
            'requested_at' => null,
        ]);

        expect($purge1->account->username)->toBe('drewroberts')
            ->and($purge2->account->username)->toBe('other');
    });

    it('scheduler does NOT process an unassigned purge, even when a default account exists', function () {
        // A connected account that, under the old global-default behavior,
        // would have been picked as the fallback.
        Account::factory()->create([
            'service' => SocialService::TWITTER,
            'username' => 'drewroberts',
            'is_active' => true,
        ]);

        $orphan = Purge::factory()->orphan()->create([
            'save' => false,
            'requested_at' => null,
        ]);

        $this->mock(TwitterAccountService::class)
            ->shouldNotReceive('deleteTweet');

        Artisan::call('purge:process');

        expect($orphan->fresh())
            ->requested_at->toBeNull()
            ->purged_at->toBeNull();
    });
});
