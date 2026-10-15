<?php

namespace App\Console\Commands;

use App\Models\ConsentCode;
use Illuminate\Console\Command;

class ConsentPruneCodes extends Command
{
    protected $signature = 'consent:prune-codes {--days=30 : How long an expired code row is kept}';

    protected $description = 'Drop consent code rows that expired long ago';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) $this->option('days'));

        $deleted = ConsentCode::where('expires_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} expired consent code(s).");

        return self::SUCCESS;
    }
}
