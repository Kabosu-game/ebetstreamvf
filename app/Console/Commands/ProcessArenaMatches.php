<?php

namespace App\Console\Commands;

use App\Models\ArenaMatch;
use Illuminate\Console\Command;

class ProcessArenaMatches extends Command
{
    protected $signature = 'arena:process';

    protected $description = 'Démarre automatiquement les matchs Arena programmés';

    public function handle(): int
    {
        $started = 0;

        ArenaMatch::where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->each(function (ArenaMatch $match) use (&$started) {
                $match->startLive();
                $started++;
                $this->line("Match #{$match->id} passé en LIVE : {$match->team1_name} vs {$match->team2_name}");
            });

        $this->info("{$started} match(s) Arena démarré(s).");

        return self::SUCCESS;
    }
}
