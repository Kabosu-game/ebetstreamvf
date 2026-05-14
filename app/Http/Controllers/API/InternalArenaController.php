<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ArenaMatch;
use App\Models\ArenaMatchPlayer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class InternalArenaController extends Controller
{
    /**
     * GET /internal/arena/{matchId}/player/{userId}
     * Retourne l'équipe du joueur dans ce match (appelé par arena-server.js)
     */
    public function playerTeam(int $matchId, int $userId)
    {
        $player = ArenaMatchPlayer::where('arena_match_id', $matchId)
            ->where('user_id', $userId)
            ->first();

        if (!$player) {
            return response()->json(['success' => false, 'team' => null, 'message' => 'Joueur non inscrit'], 404);
        }

        return response()->json([
            'success' => true,
            'team'    => $player->team,
            'class'   => $player->player_class,
        ]);
    }

    /**
     * POST /internal/arena/{matchId}/result
     * Enregistre le résultat depuis le game server et distribue les paris
     */
    public function setResult(Request $request, int $matchId)
    {
        $match = ArenaMatch::findOrFail($matchId);

        if (in_array($match->status, ['completed', 'cancelled'])) {
            return response()->json(['success' => false, 'message' => 'Match déjà clôturé'], 400);
        }

        $validated = $request->validate([
            'winner_team' => 'required|in:team1,team2,draw',
            'team1_score' => 'required|integer|min:0',
            'team2_score' => 'required|integer|min:0',
        ]);

        DB::transaction(function () use ($match, $validated) {
            $match->setResult(
                (int) $validated['team1_score'],
                (int) $validated['team2_score'],
                $validated['winner_team']
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Résultat Arena enregistré et paris distribués',
            'data'    => [
                'match_id'    => $match->id,
                'winner_team' => $validated['winner_team'],
                'team1_score' => $validated['team1_score'],
                'team2_score' => $validated['team2_score'],
            ],
        ]);
    }
}
