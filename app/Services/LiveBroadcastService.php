<?php

namespace App\Services;

use App\Models\ArenaMatch;
use App\Models\Challenge;
use App\Models\Stream;
use Illuminate\Support\Facades\DB;

class LiveBroadcastService
{
    /**
     * Arrête tous les contenus marqués LIVE (streams, défis, matchs Arena).
     */
    public static function stopAll(): array
    {
        return DB::transaction(function () {
            $now = now();

            $liveStreams = Stream::where('is_live', true)->get();
            foreach ($liveStreams as $stream) {
                $stream->sessions()
                    ->where('status', 'live')
                    ->update(['status' => 'ended', 'ended_at' => $now]);

                $stream->update([
                    'is_live' => false,
                    'ended_at' => $now,
                    'viewer_count' => 0,
                ]);
            }

            $liveChallenges = Challenge::where('is_live', true)->get();
            foreach ($liveChallenges as $challenge) {
                $challenge->update([
                    'is_live' => false,
                    'is_live_paused' => false,
                ]);
            }

            $liveArenaMatches = ArenaMatch::where('status', 'live')->get();
            foreach ($liveArenaMatches as $match) {
                $match->cancelMatch();
            }

            return [
                'streams_stopped' => $liveStreams->count(),
                'challenges_stopped' => $liveChallenges->count(),
                'arena_matches_stopped' => $liveArenaMatches->count(),
                'total' => $liveStreams->count() + $liveChallenges->count() + $liveArenaMatches->count(),
            ];
        });
    }
}
