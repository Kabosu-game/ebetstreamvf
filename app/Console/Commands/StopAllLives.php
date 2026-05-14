<?php

namespace App\Console\Commands;

use App\Services\LiveBroadcastService;
use Illuminate\Console\Command;

class StopAllLives extends Command
{
    protected $signature = 'lives:stop-all';

    protected $description = 'Arrête tous les streams, défis et matchs Arena en direct';

    public function handle(): int
    {
        $result = LiveBroadcastService::stopAll();

        $this->info("Streams arrêtés : {$result['streams_stopped']}");
        $this->info("Défis live arrêtés : {$result['challenges_stopped']}");
        $this->info("Matchs Arena arrêtés : {$result['arena_matches_stopped']}");
        $this->info("Total : {$result['total']}");

        return self::SUCCESS;
    }
}
