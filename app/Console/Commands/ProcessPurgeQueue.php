<?php

namespace App\Console\Commands;

use App\Services\PurgeService;
use Illuminate\Console\Command;

class ProcessPurgeQueue extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'purge:process';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process the next pending tweet purge request';

    /**
     * Execute the console command.
     *
     * The scheduler derives the acting user from the purge's account.
     * Unassigned purges are skipped — we never fall back to a global default.
     */
    public function handle(PurgeService $purgeService): int
    {
        $this->info('Checking for pending purge requests...');

        $purge = $purgeService->getNextPendingPurge();

        if (! $purge) {
            $this->info('No pending purge requests found.');

            return Command::SUCCESS;
        }

        $user = $purge->account?->user;

        if (! $user) {
            $this->warn(
                "Skipping purge {$purge->id}: no owning user could be derived from its account."
            );

            // Treat as a no-op for this tick — another run will pick up a
            // well-formed purge. Orphans require manual cleanup.
            return Command::SUCCESS;
        }

        $this->info("Processing purge for tweet {$purge->post_id} (user #{$user->id})...");

        $success = $purgeService->processPurge($purge, $user);

        if ($success) {
            $this->info("✓ Successfully processed purge for tweet {$purge->post_id}");

            return Command::SUCCESS;
        }

        $this->error("✗ Failed to process purge for tweet {$purge->post_id}");

        return Command::FAILURE;
    }
}
