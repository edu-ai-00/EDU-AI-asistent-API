<?php

namespace App\Console\Commands;

use App\Models\GenerationSession;
use Illuminate\Console\Command;

/**
 * Delete expired AI Course Assistant sessions and their R2 uploads (BR-D4PE8R).
 *
 * Registered on the scheduler, but sessions also prune opportunistically
 * whenever a new one is issued — production currently runs no scheduler, so the
 * command exists for manual runs and for whenever one is added.
 */
class PruneGenerationSessions extends Command
{
    protected $signature = 'generation:prune';

    protected $description = 'Delete expired course-generation sessions and their uploaded attachments';

    public function handle(): int
    {
        $count = GenerationSession::pruneExpired();

        $this->info("Pruned {$count} expired generation session(s).");

        return self::SUCCESS;
    }
}
