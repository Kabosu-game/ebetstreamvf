<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Bet;
use App\Models\GameMatch;
use App\Models\ChampionshipMatch;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class BetController extends Controller
{
    /**
     * Liste les paris d'un utilisateur
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        $query = Bet::with(['gameMatch.game', 'challenge', 'championshipMatch.championship', 'user'])
            ->where('user_id', $user->id);

        // Filtrer par statut
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $bets = $query->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($bet) {
                return $this->formatBet($bet);
            });

        return response()->json([
            'success' => true,
            'data' => $bets
        ]);
    }

    /**
     * Crée un nouveau pari
     * Supporte maintenant les matchs de championnat
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'championship_match_id' => 'required_without:game_match_id|exists:championship_matches,id',
            'game_match_id' => 'required_without:championship_match_id|exists:game_matches,id',
            'bet_type' => 'required|in:team1_win,draw,team2_win,player1_win,player2_win',
            'amount' => 'required|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Get wallet
        $wallet = Wallet::where('user_id', $user->id)->first();
        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Wallet not found'
            ], 404);
        }

        // Check if betting on championship match
        if ($request->has('championship_match_id')) {
            return $this->betOnChampionshipMatch($request, $user, $wallet);
        }

        // Old game match betting is disabled
        return response()->json([
            'success' => false,
            'message' => 'Betting on game matches is no longer available. You can now bet on championship matches or challenges instead.'
        ], 400);
    }

    /**
     * Place a bet on a championship match
     */
    private function betOnChampionshipMatch(Request $request, $user, $wallet)
    {
        $match = ChampionshipMatch::with(['player1', 'player2', 'championship'])->find($request->championship_match_id);
        
        if (!$match) {
            return response()->json([
                'success' => false,
                'message' => 'Match not found'
            ], 404);
        }

        // Check if match is bettable (scheduled or ongoing)
        if (!in_array($match->status, ['scheduled', 'ongoing'])) {
            return response()->json([
                'success' => false,
                'message' => 'Bets can only be placed on scheduled or ongoing matches'
            ], 400);
        }

        // Validate bet type
        $validBetTypes = ['player1_win', 'player2_win'];
        if ($match->championship->game === 'eFootball' || $match->championship->game === 'FC' || $match->championship->game === 'DLS') {
            $validBetTypes[] = 'draw';
        }

        if (!in_array($request->bet_type, $validBetTypes)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid bet type for this match'
            ], 400);
        }

        // Get odds based on bet type
        $odds = 0;
        if ($request->bet_type === 'player1_win') {
            $odds = $match->player1_odds;
        } elseif ($request->bet_type === 'player2_win') {
            $odds = $match->player2_odds;
        } elseif ($request->bet_type === 'draw') {
            $odds = $match->draw_odds ?? 3.00; // Default draw odds if not set
        }

        if ($odds <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid odds for this bet type'
            ], 400);
        }

        // Check balance
        $availableBalance = $wallet->balance - $wallet->locked_balance;
        if ($availableBalance < $request->amount) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient balance'
            ], 400);
        }

        // Calculate potential win
        $potentialWin = $request->amount * $odds;

        DB::beginTransaction();
        try {
            // Lock the bet amount
            $wallet->locked_balance += $request->amount;
            $wallet->save();

            // Create bet
            $bet = Bet::create([
                'user_id' => $user->id,
                'championship_match_id' => $match->id,
                'bet_type' => $request->bet_type,
                'amount' => $request->amount,
                'potential_win' => $potentialWin,
                'status' => 'pending',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Bet placed successfully',
                'data' => $bet->load(['championshipMatch.championship', 'championshipMatch.player1', 'championshipMatch.player2'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error placing bet: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Formate le pari
     */
    private function formatBet($bet)
    {
        return $bet->toArray();
    }
}
