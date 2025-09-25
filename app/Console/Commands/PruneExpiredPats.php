<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class PruneExpiredPats extends Command
{
    protected $signature = 'pat:prune';
    protected $description = 'Delete Sanctum Personal Access Tokens that are expired';

    public function handle(): int
    {
        $now = now();
        $deleted = DB::table('personal_access_tokens')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', $now)
            ->delete();

        $this->info("Pruned {$deleted} expired tokens at {$now->toDateTimeString()}");
        return self::SUCCESS;
    }
}
