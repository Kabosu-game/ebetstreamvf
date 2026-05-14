<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ArenaMatch;
use App\Models\ArenaMatchPlayer;
use App\Models\ArenaPlayerProfile;
use App\Models\Bet;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class ArenaController extends Controller
{
    public function matches(Request $request)
    {
        $query = ArenaMatch::withCount('players')
            ->orderByRaw("FIELD(status, 'live', 'scheduled', 'waiting', 'completed', 'cancelled')")
            ->orderBy('scheduled_at', 'asc');

        if ($request->filled('status')) {
            $query->whereIn('status', explode(',', $request->status));
        }

        if ($request->filled('mode')) {
            $query->where('mode', $request->mode);
        }

        if ($request->filled('league_tier')) {
            $query->where('league_tier', $request->league_tier);
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
        ]);
    }

    public function show(Request $request, $id)
    {
        $match = ArenaMatch::with([
            'players.user:id,username',
            'creator:id,username',
        ])->withCount('bets')->findOrFail($id);

        $payload = $match->toArray();
        $payload['bets_count'] = $match->bets_count;

        $user = $request->user('sanctum');
        if ($user) {
            $payload['is_joined'] = $match->players()->where('user_id', $user->id)->exists();
            $payload['my_team'] = $match->players()->where('user_id', $user->id)->value('team');
            $payload['my_bet'] = Bet::where('arena_match_id', $match->id)
                ->where('user_id', $user->id)
                ->latest()
                ->first();
        }

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }

    public function leaderboard(Request $request)
    {
        $limit = min((int) $request->get('limit', 50), 100);

        $players = ArenaPlayerProfile::with('user:id,username')
            ->orderByDesc('mmr')
            ->orderByDesc('points')
            ->limit($limit)
            ->get()
            ->map(function ($profile, $index) {
                return [
                    'position' => $index + 1,
                    'user_id' => $profile->user_id,
                    'username' => $profile->user?->username,
                    'player_class' => $profile->player_class,
                    'rank' => $profile->rank,
                    'league_tier' => $profile->league_tier,
                    'level' => $profile->level,
                    'mmr' => $profile->mmr,
                    'points' => $profile->points,
                    'matches_played' => $profile->matches_played,
                    'matches_won' => $profile->matches_won,
                    'win_rate' => $profile->matches_played > 0
                        ? round(($profile->matches_won / $profile->matches_played) * 100, 1)
                        : 0,
                ];
            });

        return response()->json([
            'success' => true,
            'data' => $players,
        ]);
    }

    public function profile(Request $request)
    {
        $user = $request->user();
        $profile = ArenaPlayerProfile::where('user_id', $user->id)->first();

        return response()->json([
            'success' => true,
            'data' => $profile,
            'has_profile' => $profile !== null,
        ]);
    }

    public function saveProfile(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'player_class' => 'required|in:attacker,defender,support,tactical',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $profile = ArenaPlayerProfile::updateOrCreate(
            ['user_id' => $user->id],
            ['player_class' => $request->player_class]
        );

        return response()->json([
            'success' => true,
            'message' => 'Profil Arena enregistré',
            'data' => $profile->load('user:id,username'),
        ]);
    }

    public function quickMatch(Request $request)
    {
        return $this->enqueueMatch($request, 'quick_match');
    }

    public function rankedMatch(Request $request)
    {
        return $this->enqueueMatch($request, 'ranked');
    }

    private function enqueueMatch(Request $request, string $mode)
    {
        $user = $request->user();

        $profile = ArenaPlayerProfile::where('user_id', $user->id)->first();
        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'Créez d\'abord votre profil Arena',
            ], 400);
        }

        $alreadyIn = ArenaMatchPlayer::where('user_id', $user->id)
            ->whereHas('match', fn ($q) => $q->whereIn('status', ['waiting', 'scheduled', 'live']))
            ->exists();

        if ($alreadyIn) {
            return response()->json([
                'success' => false,
                'message' => 'Vous participez déjà à un match actif',
            ], 400);
        }

        $waiting = ArenaMatch::where('status', 'waiting')
            ->where('mode', $mode)
            ->whereDoesntHave('players', fn ($q) => $q->where('user_id', $user->id))
            ->withCount('players')
            ->get()
            ->first(fn ($m) => $m->players_count < ($m->max_players_per_team * 2));

        DB::beginTransaction();
        try {
            if ($waiting) {
                $match = $this->addPlayerToMatch($waiting, $user, $profile);
                DB::commit();
                return response()->json([
                    'success' => true,
                    'message' => 'Match rejoint',
                    'data' => $match->load('players.user:id,username'),
                ]);
            }

            $match = ArenaMatch::create([
                'team1_name' => 'Team ' . $user->username,
                'team2_name' => 'En attente...',
                'mode' => $mode,
                'league_tier' => $profile->league_tier,
                'status' => 'waiting',
                'created_by' => $user->id,
                'scheduled_at' => now()->addMinutes(10),
                'team1_odds' => 1.90,
                'team2_odds' => 1.90,
                'match_state' => [
                    'zones_controlled' => ['team1' => 0, 'team2' => 0],
                    'duration_minutes' => 8,
                ],
            ]);

            ArenaMatchPlayer::create([
                'arena_match_id' => $match->id,
                'user_id' => $user->id,
                'team' => 'team1',
                'player_class' => $profile->player_class,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Match créé — en attente d\'adversaires',
                'data' => $match->load('players.user:id,username'),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function joinMatch(Request $request, $id)
    {
        $user = $request->user();
        $match = ArenaMatch::withCount('players')->findOrFail($id);

        if (!in_array($match->status, ['waiting', 'scheduled'])) {
            return response()->json([
                'success' => false,
                'message' => 'Ce match n\'accepte plus de joueurs',
            ], 400);
        }

        $profile = ArenaPlayerProfile::where('user_id', $user->id)->first();
        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'Créez d\'abord votre profil Arena',
            ], 400);
        }

        if ($match->players()->where('user_id', $user->id)->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Vous êtes déjà inscrit à ce match',
            ], 400);
        }

        if ($match->players_count >= $match->max_players_per_team * 2) {
            return response()->json([
                'success' => false,
                'message' => 'Match complet',
            ], 400);
        }

        DB::beginTransaction();
        try {
            $match = $this->addPlayerToMatch($match, $user, $profile);
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Inscription au match confirmée',
                'data' => $match->load('players.user:id,username'),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function leaveMatch(Request $request, $id)
    {
        $user = $request->user();
        $match = ArenaMatch::findOrFail($id);

        if ($match->status !== 'waiting') {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez quitter que les matchs en attente',
            ], 400);
        }

        $player = ArenaMatchPlayer::where('arena_match_id', $match->id)
            ->where('user_id', $user->id)
            ->first();

        if (!$player) {
            return response()->json([
                'success' => false,
                'message' => 'Vous n\'êtes pas inscrit à ce match',
            ], 404);
        }

        $player->delete();

        if ($match->players()->count() === 0) {
            $match->cancelMatch();
        }

        return response()->json([
            'success' => true,
            'message' => 'Vous avez quitté le match',
            'data' => $match->fresh()->load('players.user:id,username'),
        ]);
    }

    private function addPlayerToMatch(ArenaMatch $match, $user, ArenaPlayerProfile $profile): ArenaMatch
    {
        $team1Count = $match->players()->where('team', 'team1')->count();
        $team2Count = $match->players()->where('team', 'team2')->count();
        $team = $team1Count <= $team2Count ? 'team1' : 'team2';

        ArenaMatchPlayer::create([
            'arena_match_id' => $match->id,
            'user_id' => $user->id,
            'team' => $team,
            'player_class' => $profile->player_class,
        ]);

        $totalPlayers = $match->players()->count();
        $maxPlayers = $match->max_players_per_team * 2;

        if ($totalPlayers >= $maxPlayers) {
            $team2Player = $match->players()->where('team', 'team2')->with('user:id,username')->first();
            $match->update([
                'status' => 'scheduled',
                'scheduled_at' => now()->addMinutes(5),
                'team2_name' => $team2Player?->user?->username
                    ? 'Team ' . $team2Player->user->username
                    : 'Team Omega',
            ]);
        } elseif ($match->team2_name === 'En attente...' && $team === 'team2') {
            $match->update(['team2_name' => 'Team ' . $user->username]);
        }

        return $match->fresh();
    }

    public function createPrivateMatch(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'team1_name' => 'required|string|max:80',
            'team2_name' => 'required|string|max:80',
            'mode' => 'nullable|in:private_match,ranked,tournament',
            'scheduled_at' => 'nullable|date|after:now',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $profile = ArenaPlayerProfile::firstOrCreate(
            ['user_id' => $user->id],
            ['player_class' => 'attacker']
        );

        $match = ArenaMatch::create([
            'team1_name' => $request->team1_name,
            'team2_name' => $request->team2_name,
            'mode' => $request->get('mode', 'private_match'),
            'league_tier' => $profile->league_tier,
            'status' => 'scheduled',
            'created_by' => $user->id,
            'scheduled_at' => $request->scheduled_at ? Carbon::parse($request->scheduled_at) : now()->addHour(),
            'team1_odds' => 1.85,
            'team2_odds' => 1.95,
            'match_state' => [
                'zones_controlled' => ['team1' => 0, 'team2' => 0],
                'duration_minutes' => 8,
            ],
        ]);

        ArenaMatchPlayer::create([
            'arena_match_id' => $match->id,
            'user_id' => $user->id,
            'team' => 'team1',
            'player_class' => $profile->player_class,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Match privé créé',
            'data' => $match->load('players.user:id,username'),
        ], 201);
    }

    public function createTournamentMatch(Request $request)
    {
        $request->merge(['mode' => 'tournament']);
        return $this->createPrivateMatch($request);
    }

    public function stats()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'total_matches' => ArenaMatch::count(),
                'live_matches' => ArenaMatch::where('status', 'live')->count(),
                'scheduled_matches' => ArenaMatch::where('status', 'scheduled')->count(),
                'waiting_matches' => ArenaMatch::where('status', 'waiting')->count(),
                'total_players' => ArenaPlayerProfile::count(),
                'currency' => 'EBT',
                'bet_system' => 'ESBS',
                'league' => 'EOL',
            ],
        ]);
    }
}
