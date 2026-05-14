<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ArenaMatch;
use App\Models\ArenaMatchPlayer;
use App\Models\ArenaPlayerProfile;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AdminArenaController extends Controller
{
    public function index(Request $request)
    {
        $query = ArenaMatch::with(['creator:id,username'])
            ->withCount(['players', 'bets'])
            ->orderByRaw("FIELD(status, 'live', 'scheduled', 'waiting', 'completed', 'cancelled')")
            ->orderByDesc('scheduled_at');

        if ($request->filled('status')) {
            $query->whereIn('status', explode(',', $request->status));
        }

        if ($request->filled('mode')) {
            $query->where('mode', $request->mode);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('team1_name', 'like', "%{$search}%")
                    ->orWhere('team2_name', 'like', "%{$search}%");
            });
        }

        $matches = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $matches->items(),
            'meta' => [
                'current_page' => $matches->currentPage(),
                'last_page' => $matches->lastPage(),
                'total' => $matches->total(),
            ],
            'stats' => [
                'total' => ArenaMatch::count(),
                'live' => ArenaMatch::where('status', 'live')->count(),
                'scheduled' => ArenaMatch::where('status', 'scheduled')->count(),
                'waiting' => ArenaMatch::where('status', 'waiting')->count(),
                'completed' => ArenaMatch::where('status', 'completed')->count(),
            ],
        ]);
    }

    public function show($id)
    {
        $match = ArenaMatch::with([
            'players.user:id,username',
            'creator:id,username',
            'bets.user:id,username',
        ])->withCount('bets')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $match,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'team1_name' => 'required|string|max:80',
            'team2_name' => 'required|string|max:80',
            'mode' => 'required|in:quick_match,ranked,tournament,private_match',
            'league_tier' => 'nullable|in:amateur,semi_pro,pro,champion',
            'status' => 'nullable|in:waiting,scheduled,live',
            'scheduled_at' => 'nullable|date',
            'team1_odds' => 'nullable|numeric|min:1.01|max:50',
            'team2_odds' => 'nullable|numeric|min:1.01|max:50',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $admin = $request->user();

        $match = ArenaMatch::create([
            'team1_name' => $request->team1_name,
            'team2_name' => $request->team2_name,
            'mode' => $request->mode,
            'league_tier' => $request->get('league_tier', 'amateur'),
            'status' => $request->get('status', 'scheduled'),
            'scheduled_at' => $request->scheduled_at ? Carbon::parse($request->scheduled_at) : now()->addHour(),
            'team1_odds' => $request->get('team1_odds', 1.90),
            'team2_odds' => $request->get('team2_odds', 1.90),
            'created_by' => $admin->id,
            'match_state' => [
                'zones_controlled' => ['team1' => 0, 'team2' => 0],
                'duration_minutes' => 8,
            ],
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Match Arena créé',
            'data' => $match->load('creator:id,username'),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $match = ArenaMatch::findOrFail($id);

        if (in_array($match->status, ['completed', 'cancelled'])) {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de modifier un match terminé ou annulé',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'team1_name' => 'sometimes|string|max:80',
            'team2_name' => 'sometimes|string|max:80',
            'mode' => 'sometimes|in:quick_match,ranked,tournament,private_match',
            'league_tier' => 'sometimes|in:amateur,semi_pro,pro,champion',
            'status' => 'sometimes|in:waiting,scheduled,live',
            'scheduled_at' => 'nullable|date',
            'team1_odds' => 'sometimes|numeric|min:1.01|max:50',
            'team2_odds' => 'sometimes|numeric|min:1.01|max:50',
            'team1_score' => 'sometimes|integer|min:0',
            'team2_score' => 'sometimes|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $request->only([
            'team1_name', 'team2_name', 'mode', 'league_tier', 'status',
            'team1_odds', 'team2_odds', 'team1_score', 'team2_score',
        ]);

        if ($request->filled('scheduled_at')) {
            $data['scheduled_at'] = Carbon::parse($request->scheduled_at);
        }

        $match->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Match mis à jour',
            'data' => $match->fresh()->load(['players.user:id,username', 'creator:id,username']),
        ]);
    }

    public function startLive($id)
    {
        $match = ArenaMatch::findOrFail($id);

        if (!in_array($match->status, ['waiting', 'scheduled'])) {
            return response()->json([
                'success' => false,
                'message' => 'Seuls les matchs en attente ou programmés peuvent démarrer',
            ], 400);
        }

        $match->startLive();

        return response()->json([
            'success' => true,
            'message' => 'Match passé en LIVE',
            'data' => $match->fresh()->load(['players.user:id,username']),
        ]);
    }

    public function setResult(Request $request, $id)
    {
        $match = ArenaMatch::findOrFail($id);

        if (in_array($match->status, ['completed', 'cancelled'])) {
            return response()->json([
                'success' => false,
                'message' => 'Ce match est déjà clôturé',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'team1_score' => 'required|integer|min:0',
            'team2_score' => 'required|integer|min:0',
            'winner_team' => 'nullable|in:team1,team2,draw',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        DB::transaction(function () use ($match, $request) {
            $match->setResult(
                (int) $request->team1_score,
                (int) $request->team2_score,
                $request->winner_team
            );
        });

        return response()->json([
            'success' => true,
            'message' => 'Résultat enregistré — paris et stats joueurs mis à jour',
            'data' => $match->fresh()->load(['players.user:id,username', 'bets']),
        ]);
    }

    public function cancel($id)
    {
        $match = ArenaMatch::findOrFail($id);

        if (in_array($match->status, ['completed', 'cancelled'])) {
            return response()->json([
                'success' => false,
                'message' => 'Ce match est déjà clôturé',
            ], 400);
        }

        DB::transaction(function () use ($match) {
            $match->cancelMatch();
        });

        return response()->json([
            'success' => true,
            'message' => 'Match annulé — paris remboursés',
            'data' => $match->fresh(),
        ]);
    }

    public function destroy($id)
    {
        $match = ArenaMatch::withCount('bets')->findOrFail($id);

        if ($match->status === 'live') {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer un match en direct',
            ], 400);
        }

        if ($match->bets_count > 0 && $match->status === 'completed') {
            return response()->json([
                'success' => false,
                'message' => 'Impossible de supprimer un match terminé avec des paris',
            ], 400);
        }

        ArenaMatchPlayer::where('arena_match_id', $match->id)->delete();
        $match->delete();

        return response()->json([
            'success' => true,
            'message' => 'Match supprimé',
        ]);
    }
}
