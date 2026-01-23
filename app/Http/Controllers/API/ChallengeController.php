<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Challenge;
use App\Models\ChallengeMessage;
use App\Models\ChallengeStopRequest;
use App\Models\Wallet;
use App\Models\User;
use App\Models\Clan;
use App\Models\Transaction;
use App\Models\Bet;
use App\Models\Game;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ChallengeController extends Controller
{
    /**
     * Get all challenges (with filters)
     */
    public function index(Request $request)
    {
        $query = Challenge::with(['creator', 'opponent', 'creatorClan', 'opponentClan']);

        // Filter by type (user or clan)
        if ($request->has('type')) {
            if ($request->type === 'clan') {
                $query->clanChallenges();
            } elseif ($request->type === 'user') {
                $query->userChallenges();
            }
        }

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter open challenges only
        if ($request->has('open_only') && $request->open_only) {
            $query->open();
        }

        // Filter by game
        if ($request->has('game')) {
            $query->where('game', 'like', '%' . $request->game . '%');
        }

        // Get user's challenges (including clan challenges)
        if ($request->has('my_challenges') && $request->my_challenges) {
            $user = $request->user();
            $query->where(function($q) use ($user) {
                $q->forUser($user->id)
                  ->orWhereHas('creatorClan', function($clanQuery) use ($user) {
                      $clanQuery->whereHas('members', function($memberQuery) use ($user) {
                          $memberQuery->where('user_id', $user->id);
                      });
                  })
                  ->orWhereHas('opponentClan', function($clanQuery) use ($user) {
                      $clanQuery->whereHas('members', function($memberQuery) use ($user) {
                          $memberQuery->where('user_id', $user->id);
                      });
                  });
            });
        }

        // Filter by clan
        if ($request->has('clan_id')) {
            $query->forClan($request->clan_id);
        }

        $challenges = $query->orderBy('created_at', 'desc')->paginate(12);

        return response()->json([
            'success' => true,
            'data' => $challenges
        ]);
    }

    /**
     * Get a specific challenge
     */
    public function show($id)
    {
        $challenge = Challenge::with(['creator', 'opponent', 'creatorClan', 'opponentClan'])->find($id);

        if (!$challenge) {
            return response()->json([
                'success' => false,
                'message' => 'Challenge not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $challenge
        ]);
    }

    /**
     * Create a new challenge
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'game' => 'required|string|max:255',
            'bet_amount' => 'required|numeric|min:500|max:1000000', // Minimum 500 EBT (5$), max 1,000,000 EBT (10,000$)
            'expires_at' => 'nullable|date|after:now',
            'opponent_username' => 'nullable|string|exists:users,username',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // If opponent is specified, validate it
        $opponent = null;
        if ($request->has('opponent_username') && $request->opponent_username) {
            $opponent = User::where('username', $request->opponent_username)->first();
            
            if (!$opponent) {
                return response()->json([
                    'success' => false,
                    'message' => 'User not found'
                ], 404);
            }

            if ($opponent->id === $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot challenge yourself'
                ], 400);
            }

            // Check if opponent has sufficient balance
            $opponentWallet = Wallet::where('user_id', $opponent->id)->first();
            if (!$opponentWallet) {
                return response()->json([
                    'success' => false,
                    'message' => 'Opponent wallet not found'
                ], 404);
            }

            $opponentAvailableBalance = $opponentWallet->balance - $opponentWallet->locked_balance;
            if ($opponentAvailableBalance < $request->bet_amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Opponent does not have sufficient balance'
                ], 400);
            }
        }

        // Check if user has sufficient balance
        $wallet = Wallet::where('user_id', $user->id)->first();
        
        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Wallet not found'
            ], 404);
        }

        $betAmount = $request->bet_amount;
        $availableBalance = $wallet->balance - $wallet->locked_balance;

        if ($availableBalance < $betAmount) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient balance'
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Débiter le solde et verrouiller le montant pour le créateur
            $wallet->balance -= $betAmount;
            $wallet->locked_balance += $betAmount;
            $wallet->save();

            // If opponent is specified, debit and lock their balance too and set status to accepted
            $status = 'open';
            if ($opponent) {
                $opponentWallet = Wallet::where('user_id', $opponent->id)->first();
                $opponentWallet->balance -= $betAmount;
                $opponentWallet->locked_balance += $betAmount;
                $opponentWallet->save();
                $status = 'accepted';
            }

            // Create challenge
            $challenge = Challenge::create([
                'type' => 'user', // Type de défi entre utilisateurs
                'creator_id' => $user->id,
                'opponent_id' => $opponent ? $opponent->id : null,
                'game' => $request->game,
                'bet_amount' => $betAmount,
                'status' => $status,
                'expires_at' => $request->expires_at ?? now()->addDays(7),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $opponent ? 'Direct challenge created successfully' : 'Challenge created successfully',
                'data' => $challenge->load(['creator', 'opponent'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error creating challenge: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Accept a challenge
     */
    public function accept(Request $request, $id)
    {
        $user = $request->user();
        $challenge = Challenge::find($id);

        if (!$challenge) {
            return response()->json([
                'success' => false,
                'message' => 'Challenge not found'
            ], 404);
        }

        if ($challenge->status !== 'open') {
            return response()->json([
                'success' => false,
                'message' => 'Challenge is not open'
            ], 400);
        }

        if ($challenge->creator_id === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot accept your own challenge'
            ], 400);
        }

        // Check if challenge has expired
        if ($challenge->expires_at && $challenge->expires_at < now()) {
            return response()->json([
                'success' => false,
                'message' => 'Challenge has expired'
            ], 400);
        }

        // Check if user has sufficient balance
        $wallet = Wallet::where('user_id', $user->id)->first();
        
        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Wallet not found'
            ], 404);
        }

        $availableBalance = $wallet->balance - $wallet->locked_balance;

        // Vérifier que l'utilisateur a au moins 1000 EBT pour pouvoir utiliser les coins
        if ($availableBalance < 1000) {
            return response()->json([
                'success' => false,
                'message' => 'You must have at least 1000 EBT (10$) to accept challenges. Your available balance is ' . number_format($availableBalance, 0) . ' EBT'
            ], 400);
        }

        if ($availableBalance < $challenge->bet_amount) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient balance. Your available balance is ' . number_format($availableBalance, 0) . ' EBT'
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Débiter le solde et verrouiller le montant pour l'adversaire
            $wallet->balance -= $challenge->bet_amount;
            $wallet->locked_balance += $challenge->bet_amount;
            $wallet->save();

            // Update challenge
            $challenge->opponent_id = $user->id;
            $challenge->status = 'accepted';
            $challenge->save();

            // Le défi est maintenant accepté et disponible pour les paris
            // Les autres joueurs peuvent parier via l'endpoint POST /challenges/{id}/bet

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Challenge accepted successfully',
                'data' => $challenge->load(['creator', 'opponent'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error accepting challenge: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel a challenge
     */
    public function cancel(Request $request, $id)
    {
        $user = $request->user();
        $challenge = Challenge::find($id);

        if (!$challenge) {
            return response()->json([
                'success' => false,
                'message' => 'Challenge not found'
            ], 404);
        }

        // Only creator can cancel, and only if challenge is open
        if ($challenge->creator_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Only the creator can cancel this challenge'
            ], 403);
        }

        if ($challenge->status !== 'open') {
            return response()->json([
                'success' => false,
                'message' => 'Challenge cannot be cancelled'
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Unlock the bet amount
            $wallet = Wallet::where('user_id', $user->id)->first();
            if ($wallet) {
                $wallet->locked_balance -= $challenge->bet_amount;
                $wallet->save();
            }

            // Update challenge status
            $challenge->status = 'cancelled';
            $challenge->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Challenge cancelled successfully',
                'data' => $challenge
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error cancelling challenge: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Submit scores for a challenge
     */
    public function submitScores(Request $request, $id)
    {
        $user = $request->user();
        $challenge = Challenge::find($id);

        if (!$challenge) {
            return response()->json([
                'success' => false,
                'message' => 'Challenge not found'
            ], 404);
        }

        if ($challenge->status !== 'accepted' && $challenge->status !== 'in_progress') {
            return response()->json([
                'success' => false,
                'message' => 'Challenge is not in a valid state for score submission'
            ], 400);
        }

        // Check if user is part of the challenge
        if ($challenge->creator_id !== $user->id && $challenge->opponent_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not part of this challenge'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'score' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Update score based on user role
            if ($challenge->creator_id === $user->id) {
                $challenge->creator_score = $request->score;
            } else {
                $challenge->opponent_score = $request->score;
            }

            // If both scores are set, determine winner and complete challenge
            if ($challenge->creator_score !== null && $challenge->opponent_score !== null) {
                $challenge->status = 'completed';
                
                // Determine winner and distribute winnings
                $this->distributeWinnings($challenge);
            } else {
                $challenge->status = 'in_progress';
            }

            $challenge->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Score submitted successfully',
                'data' => $challenge->load(['creator', 'opponent'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error submitting score: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Distribute winnings after challenge completion
     */
    private function distributeWinnings(Challenge $challenge)
    {
        // Le pot total est la somme des deux mises
        $totalPot = $challenge->bet_amount * 2; // Both players bet the same amount
        
        $creatorWallet = Wallet::where('user_id', $challenge->creator_id)->first();
        $opponentWallet = Wallet::where('user_id', $challenge->opponent_id)->first();

        if (!$creatorWallet || !$opponentWallet) {
            throw new \Exception('Wallet not found for one of the players');
        }

        // Débloquer les montants misés (remettre dans le solde disponible)
        $creatorWallet->locked_balance -= $challenge->bet_amount;
        $opponentWallet->locked_balance -= $challenge->bet_amount;

        if ($creatorWallet->locked_balance < 0) {
            $creatorWallet->locked_balance = 0;
        }
        if ($opponentWallet->locked_balance < 0) {
            $opponentWallet->locked_balance = 0;
        }

        if ($challenge->creator_score > $challenge->opponent_score) {
            // Creator wins
            // Le gagnant reçoit seulement le pari de l'adversaire (pas son propre pari)
            // Son propre pari est déjà débité, donc on lui donne seulement le pari de l'adversaire
            $creatorWallet->balance += $challenge->bet_amount; // Reçoit seulement le pari de l'adversaire
            
            // Le perdant perd son pari (déjà débité lors de l'acceptation, donc rien à faire)
            // On débloque juste son montant verrouillé
            
            // Créer transaction pour le gagnant
            if (Schema::hasColumn('transactions', 'meta')) {
                $creatorWallet->user->transactions()->create([
                    'wallet_id' => $creatorWallet->id,
                    'type' => 'deposit',
                    'amount' => $challenge->bet_amount, // Reçoit seulement le pari de l'adversaire
                    'status' => 'confirmed',
                    'provider' => 'challenge_win',
                    'txid' => 'CHALLENGE_WIN_' . $challenge->id . '_' . $challenge->creator_id . '_' . now()->format('YmdHis'),
                    'meta' => json_encode(['challenge_id' => $challenge->id, 'opponent_id' => $challenge->opponent_id])
                ]);
            } else {
                $creatorWallet->user->transactions()->create([
                    'wallet_id' => $creatorWallet->id,
                    'type' => 'deposit',
                    'amount' => $challenge->bet_amount, // Reçoit seulement le pari de l'adversaire
                    'status' => 'confirmed',
                    'provider' => 'challenge_win',
                    'txid' => 'CHALLENGE_WIN_' . $challenge->id . '_' . $challenge->creator_id . '_' . now()->format('YmdHis'),
                ]);
            }

            // Créer transaction pour le perdant (déjà débité lors de l'acceptation)
            if (Schema::hasColumn('transactions', 'meta')) {
                $opponentWallet->user->transactions()->create([
                    'wallet_id' => $opponentWallet->id,
                    'type' => 'bet',
                    'amount' => $challenge->bet_amount,
                    'status' => 'confirmed',
                    'provider' => 'challenge_loss',
                    'txid' => 'CHALLENGE_LOSS_' . $challenge->id . '_' . $challenge->opponent_id . '_' . now()->format('YmdHis'),
                    'meta' => json_encode(['challenge_id' => $challenge->id, 'winner_id' => $challenge->creator_id])
                ]);
            } else {
                $opponentWallet->user->transactions()->create([
                    'wallet_id' => $opponentWallet->id,
                    'type' => 'bet',
                    'amount' => $challenge->bet_amount,
                    'status' => 'confirmed',
                    'provider' => 'challenge_loss',
                    'txid' => 'CHALLENGE_LOSS_' . $challenge->id . '_' . $challenge->opponent_id . '_' . now()->format('YmdHis'),
                ]);
            }

        } elseif ($challenge->opponent_score > $challenge->creator_score) {
            // Opponent wins
            // Le gagnant reçoit seulement le pari de l'adversaire (pas son propre pari)
            $opponentWallet->balance += $challenge->bet_amount; // Reçoit seulement le pari de l'adversaire
            
            // Le perdant perd son pari (déjà débité lors de l'acceptation)
            
            // Créer transaction pour le gagnant
            if (Schema::hasColumn('transactions', 'meta')) {
                $opponentWallet->user->transactions()->create([
                    'wallet_id' => $opponentWallet->id,
                    'type' => 'deposit',
                    'amount' => $challenge->bet_amount, // Reçoit seulement le pari de l'adversaire
                    'status' => 'confirmed',
                    'provider' => 'challenge_win',
                    'txid' => 'CHALLENGE_WIN_' . $challenge->id . '_' . $challenge->opponent_id . '_' . now()->format('YmdHis'),
                    'meta' => json_encode(['challenge_id' => $challenge->id, 'opponent_id' => $challenge->creator_id])
                ]);
            } else {
                $opponentWallet->user->transactions()->create([
                    'wallet_id' => $opponentWallet->id,
                    'type' => 'deposit',
                    'amount' => $challenge->bet_amount, // Reçoit seulement le pari de l'adversaire
                    'status' => 'confirmed',
                    'provider' => 'challenge_win',
                    'txid' => 'CHALLENGE_WIN_' . $challenge->id . '_' . $challenge->opponent_id . '_' . now()->format('YmdHis'),
                ]);
            }

            // Créer transaction pour le perdant (déjà débité lors de l'acceptation)
            if (Schema::hasColumn('transactions', 'meta')) {
                $creatorWallet->user->transactions()->create([
                    'wallet_id' => $creatorWallet->id,
                    'type' => 'bet',
                    'amount' => $challenge->bet_amount,
                    'status' => 'confirmed',
                    'provider' => 'challenge_loss',
                    'txid' => 'CHALLENGE_LOSS_' . $challenge->id . '_' . $challenge->creator_id . '_' . now()->format('YmdHis'),
                    'meta' => json_encode(['challenge_id' => $challenge->id, 'winner_id' => $challenge->opponent_id])
                ]);
            } else {
                $creatorWallet->user->transactions()->create([
                    'wallet_id' => $creatorWallet->id,
                    'type' => 'bet',
                    'amount' => $challenge->bet_amount,
                    'status' => 'confirmed',
                    'provider' => 'challenge_loss',
                    'txid' => 'CHALLENGE_LOSS_' . $challenge->id . '_' . $challenge->creator_id . '_' . now()->format('YmdHis'),
                ]);
            }

        } else {
            // Draw - refund both players
            $creatorWallet->balance += $challenge->bet_amount;
            $opponentWallet->balance += $challenge->bet_amount;

            // Créer transactions pour les remboursements
            if (Schema::hasColumn('transactions', 'meta')) {
                $creatorWallet->user->transactions()->create([
                    'wallet_id' => $creatorWallet->id,
                    'type' => 'deposit',
                    'amount' => $challenge->bet_amount,
                    'status' => 'confirmed',
                    'provider' => 'challenge_draw',
                    'txid' => 'CHALLENGE_DRAW_' . $challenge->id . '_' . $challenge->creator_id . '_' . now()->format('YmdHis'),
                    'meta' => json_encode(['challenge_id' => $challenge->id, 'result' => 'draw'])
                ]);

                $opponentWallet->user->transactions()->create([
                    'wallet_id' => $opponentWallet->id,
                    'type' => 'deposit',
                    'amount' => $challenge->bet_amount,
                    'status' => 'confirmed',
                    'provider' => 'challenge_draw',
                    'txid' => 'CHALLENGE_DRAW_' . $challenge->id . '_' . $challenge->opponent_id . '_' . now()->format('YmdHis'),
                    'meta' => json_encode(['challenge_id' => $challenge->id, 'result' => 'draw'])
                ]);
            } else {
                $creatorWallet->user->transactions()->create([
                    'wallet_id' => $creatorWallet->id,
                    'type' => 'deposit',
                    'amount' => $challenge->bet_amount,
                    'status' => 'confirmed',
                    'provider' => 'challenge_draw',
                    'txid' => 'CHALLENGE_DRAW_' . $challenge->id . '_' . $challenge->creator_id . '_' . now()->format('YmdHis'),
                ]);

                $opponentWallet->user->transactions()->create([
                    'wallet_id' => $opponentWallet->id,
                    'type' => 'deposit',
                    'amount' => $challenge->bet_amount,
                    'status' => 'confirmed',
                    'provider' => 'challenge_draw',
                    'txid' => 'CHALLENGE_DRAW_' . $challenge->id . '_' . $challenge->opponent_id . '_' . now()->format('YmdHis'),
                ]);
            }
        }

        $creatorWallet->save();
        $opponentWallet->save();

        // Résoudre les paris sur ce défi
        $this->resolveChallengeBets($challenge);
    }

    /**
     * Résoudre les paris sur un défi terminé
     */
    private function resolveChallengeBets(Challenge $challenge)
    {
        // Déterminer le gagnant
        $winner = null;
        if ($challenge->creator_score > $challenge->opponent_score) {
            $winner = 'creator_win';
        } elseif ($challenge->opponent_score > $challenge->creator_score) {
            $winner = 'opponent_win';
        } else {
            // Match nul - rembourser tous les paris
            $bets = Bet::where('challenge_id', $challenge->id)
                ->where('status', 'pending')
                ->get();

            foreach ($bets as $bet) {
                $wallet = Wallet::where('user_id', $bet->user_id)->first();
                if ($wallet) {
                    $wallet->balance += $bet->amount;
                    $wallet->save();
                }
                $bet->status = 'cancelled';
                $bet->save();
            }
            return;
        }

        // Récupérer tous les paris en attente sur ce défi
        $bets = Bet::where('challenge_id', $challenge->id)
            ->where('status', 'pending')
            ->with('user')
            ->get();

        foreach ($bets as $bet) {
            $wallet = Wallet::where('user_id', $bet->user_id)->first();
            
            if (!$wallet) {
                continue;
            }

            if ($bet->bet_type === $winner) {
                // Pari gagnant - verser les gains
                $wallet->balance += $bet->potential_win;
                $wallet->save();
                $bet->status = 'won';
            } else {
                // Pari perdant
                $bet->status = 'lost';
            }
            
            $bet->save();
        }
    }

    /**
     * Get messages for a challenge (only creator and opponent can see)
     */
    public function getMessages(Request $request, $id)
    {
        $user = $request->user();
        $challenge = Challenge::findOrFail($id);

        // Check if user is creator or opponent
        if ($challenge->creator_id !== $user->id && $challenge->opponent_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only the creator and opponent can view messages.'
            ], 403);
        }

        $messages = ChallengeMessage::where('challenge_id', $challenge->id)
            ->where('is_deleted', false)
            ->with(['user:id,username'])
            ->latest()
            ->paginate(50);

        return response()->json([
            'success' => true,
            'data' => $messages
        ]);
    }

    /**
     * Send a message to challenge chat (only creator and opponent can send)
     */
    public function sendMessage(Request $request, $id)
    {
        $user = $request->user();
        $challenge = Challenge::findOrFail($id);

        // Check if user is creator or opponent
        if ($challenge->creator_id !== $user->id && $challenge->opponent_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only the creator and opponent can send messages.'
            ], 403);
        }

        // Check if challenge has been accepted (opponent must exist)
        if (!$challenge->opponent_id) {
            return response()->json([
                'success' => false,
                'message' => 'Challenge must be accepted before messages can be sent.'
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $message = ChallengeMessage::create([
            'challenge_id' => $challenge->id,
            'user_id' => $user->id,
            'message' => $request->message,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Message sent successfully',
            'data' => $message->load('user:id,username')
        ], 201);
    }

    /**
     * Delete a message (only the sender can delete)
     */
    public function deleteMessage(Request $request, $id, $messageId)
    {
        $user = $request->user();
        $challenge = Challenge::findOrFail($id);

        // Check if user is creator or opponent
        if ($challenge->creator_id !== $user->id && $challenge->opponent_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        $message = ChallengeMessage::where('challenge_id', $challenge->id)
            ->findOrFail($messageId);

        // Only the sender can delete their message
        if ($message->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You can only delete your own messages.'
            ], 403);
        }

        $message->update(['is_deleted' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Message deleted successfully'
        ]);
    }

    /**
     * Request to stop a challenge (initiate stop request)
     */
    public function requestStop(Request $request, $id)
    {
        $user = $request->user();
        $challenge = Challenge::findOrFail($id);

        // Check if user is creator or opponent
        if ($challenge->creator_id !== $user->id && $challenge->opponent_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Only the creator and opponent can request to stop the challenge.'
            ], 403);
        }

        // Check if challenge is in a valid state
        if (!in_array($challenge->status, ['accepted', 'in_progress'])) {
            return response()->json([
                'success' => false,
                'message' => 'Challenge must be accepted or in progress to request stop.'
            ], 400);
        }

        // Check if opponent exists
        if (!$challenge->opponent_id) {
            return response()->json([
                'success' => false,
                'message' => 'Challenge must be accepted before requesting stop.'
            ], 400);
        }

        // Check if there's already a stop request
        $existingRequest = ChallengeStopRequest::where('challenge_id', $challenge->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->first();

        if ($existingRequest) {
            // If the other player already requested, confirm it
            if ($existingRequest->initiator_id !== $user->id) {
                $existingRequest->update([
                    'confirmer_id' => $user->id,
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                ]);

                return response()->json([
                    'success' => true,
                    'message' => 'Stop request confirmed. Waiting for admin approval.',
                    'data' => $existingRequest->load(['initiator:id,username', 'confirmer:id,username'])
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'You have already requested to stop this challenge. Waiting for opponent confirmation.'
                ], 400);
            }
        }

        // Create new stop request
        $stopRequest = ChallengeStopRequest::create([
            'challenge_id' => $challenge->id,
            'initiator_id' => $user->id,
            'status' => 'pending',
            'reason' => $request->reason ?? null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Stop request created. Waiting for opponent confirmation.',
            'data' => $stopRequest->load('initiator:id,username')
        ], 201);
    }

    /**
     * Get stop request status for a challenge
     */
    public function getStopRequest(Request $request, $id)
    {
        $user = $request->user();
        $challenge = Challenge::findOrFail($id);

        // Check if user is creator or opponent
        if ($challenge->creator_id !== $user->id && $challenge->opponent_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized.'
            ], 403);
        }

        $stopRequest = ChallengeStopRequest::where('challenge_id', $challenge->id)
            ->with(['initiator:id,username', 'confirmer:id,username'])
            ->first();

        return response()->json([
            'success' => true,
            'data' => $stopRequest
        ]);
    }

    /**
     * Cancel stop request (only the initiator can cancel if not confirmed)
     */
    public function cancelStopRequest(Request $request, $id)
    {
        $user = $request->user();
        $challenge = Challenge::findOrFail($id);

        $stopRequest = ChallengeStopRequest::where('challenge_id', $challenge->id)
            ->where('status', 'pending')
            ->first();

        if (!$stopRequest) {
            return response()->json([
                'success' => false,
                'message' => 'No pending stop request found.'
            ], 404);
        }

        if ($stopRequest->initiator_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Only the initiator can cancel the stop request.'
            ], 403);
        }

        $stopRequest->delete();

        return response()->json([
            'success' => true,
            'message' => 'Stop request cancelled successfully.'
        ]);
    }

    /**
     * Create a clan challenge (between clans)
     */
    public function storeClanChallenge(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'game' => 'required|string|max:255',
            'bet_amount' => 'required|numeric|min:500|max:1000000', // Minimum 500 EBT (5$), max 1,000,000 EBT (10,000$)
            'expires_at' => 'nullable|date|after:now',
            'opponent_clan_id' => 'nullable|integer|exists:clans,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Vérifier que l'utilisateur est membre d'un clan
        $creatorClan = Clan::whereHas('members', function($query) use ($user) {
            $query->where('user_id', $user->id);
        })->first();

        if (!$creatorClan) {
            return response()->json([
                'success' => false,
                'message' => 'You must be a member of a clan to create a clan challenge'
            ], 400);
        }

        // Si un clan adversaire est spécifié, valider
        $opponentClan = null;
        if ($request->has('opponent_clan_id') && $request->opponent_clan_id) {
            $opponentClan = Clan::find($request->opponent_clan_id);
            
            if (!$opponentClan) {
                return response()->json([
                    'success' => false,
                    'message' => 'Opponent clan not found'
                ], 404);
            }

            if ($opponentClan->id === $creatorClan->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'You cannot challenge your own clan'
                ], 400);
            }
        }

        // Vérifier que l'utilisateur a suffisamment de balance
        $wallet = Wallet::where('user_id', $user->id)->first();
        
        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Wallet not found'
            ], 404);
        }

        $betAmount = $request->bet_amount;
        $availableBalance = $wallet->balance - $wallet->locked_balance;

        if ($availableBalance < $betAmount) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient balance'
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Verrouiller le montant pour le créateur
            $wallet->locked_balance += $betAmount;
            $wallet->save();

            // Si un clan adversaire est spécifié, le défi est automatiquement accepté
            $status = 'open';
            if ($opponentClan) {
                // Optionnel: verrouiller aussi la balance du leader du clan adversaire
                // Pour l'instant, on accepte automatiquement le défi
                $status = 'accepted';
            }

            // Créer le défi entre clans
            $challenge = Challenge::create([
                'type' => 'clan',
                'creator_id' => $user->id, // L'utilisateur qui crée le défi
                'creator_clan_id' => $creatorClan->id,
                'opponent_clan_id' => $opponentClan ? $opponentClan->id : null,
                'game' => $request->game,
                'bet_amount' => $betAmount,
                'status' => $status,
                'expires_at' => $request->expires_at ?? now()->addDays(7),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $opponentClan ? 'Clan challenge created and accepted successfully' : 'Clan challenge created successfully',
                'data' => $challenge->load(['creator', 'creatorClan', 'opponentClan'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error creating clan challenge: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Accept a clan challenge (by a member of the challenged clan)
     */
    public function acceptClanChallenge(Request $request, $id)
    {
        $user = $request->user();
        $challenge = Challenge::find($id);

        if (!$challenge || $challenge->type !== 'clan') {
            return response()->json([
                'success' => false,
                'message' => 'Clan challenge not found'
            ], 404);
        }

        if ($challenge->status !== 'open') {
            return response()->json([
                'success' => false,
                'message' => 'Challenge is not open'
            ], 400);
        }

        // Vérifier que l'utilisateur est membre du clan qui peut accepter le défi
        if (!$challenge->opponent_clan_id) {
            return response()->json([
                'success' => false,
                'message' => 'This challenge has a specific opponent clan'
            ], 400);
        }

        $opponentClan = Clan::find($challenge->opponent_clan_id);
        if (!$opponentClan || !$opponentClan->isMember($user->id)) {
            return response()->json([
                'success' => false,
                'message' => 'You must be a member of the challenged clan to accept this challenge'
            ], 400);
        }

        // Vérifier si le défi a expiré
        if ($challenge->expires_at && $challenge->expires_at < now()) {
            return response()->json([
                'success' => false,
                'message' => 'Challenge has expired'
            ], 400);
        }

        // Vérifier que l'utilisateur a suffisamment de balance
        $wallet = Wallet::where('user_id', $user->id)->first();
        
        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Wallet not found'
            ], 404);
        }

        $availableBalance = $wallet->balance - $wallet->locked_balance;

        // Vérifier que l'utilisateur a au moins 1000 EBT pour pouvoir utiliser les coins
        if ($availableBalance < 1000) {
            return response()->json([
                'success' => false,
                'message' => 'You must have at least 1000 EBT (10$) to accept challenges. Your available balance is ' . number_format($availableBalance, 0) . ' EBT'
            ], 400);
        }

        if ($availableBalance < $challenge->bet_amount) {
            return response()->json([
                'success' => false,
                'message' => 'Insufficient balance. Your available balance is ' . number_format($availableBalance, 0) . ' EBT'
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Débiter le solde et verrouiller le montant pour l'utilisateur qui accepte
            $wallet->balance -= $challenge->bet_amount;
            $wallet->locked_balance += $challenge->bet_amount;
            $wallet->save();

            // Mettre à jour le statut du défi
            $challenge->status = 'accepted';
            $challenge->opponent_id = $user->id; // L'utilisateur qui accepte représente son clan
            $challenge->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Clan challenge accepted successfully',
                'data' => $challenge->load(['creator', 'opponent', 'creatorClan', 'opponentClan'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error accepting clan challenge: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Start screen recording for a challenge
     */
    public function startScreenRecording(Request $request, $id)
    {
        $user = $request->user();
        $challenge = Challenge::find($id);

        if (!$challenge) {
            return response()->json([
                'success' => false,
                'message' => 'Challenge not found'
            ], 404);
        }

        // Seul le créateur peut démarrer l'enregistrement d'écran et le live
        if ($challenge->creator_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Only the challenge creator can start screen recording and live streaming'
            ], 403);
        }

        // Check if challenge is open, accepted or in progress
        if (!in_array($challenge->status, ['open', 'accepted', 'in_progress'])) {
            return response()->json([
                'success' => false,
                'message' => 'Challenge must be open, accepted or in progress to start recording'
            ], 400);
        }
        
        // Check if already live
        if ($challenge->is_live) {
            return response()->json([
                'success' => false,
                'message' => 'Live streaming is already active for this challenge'
            ], 400);
        }

        // Determine if user is creator (always true here, but kept for consistency)
        $isCreator = $challenge->creator_id === $user->id;
        
        DB::beginTransaction();
        try {
            // Générer une clé de stream unique
            $streamKey = 'challenge_' . $challenge->id . '_' . time() . '_' . bin2hex(random_bytes(8));
            
            // URL RTMP pour le streaming (à configurer selon votre serveur RTMP)
            $rtmpUrl = env('RTMP_SERVER_URL', 'rtmp://localhost:1935/live');
            
            // URL publique pour voir le stream
            $publicStreamUrl = env('STREAM_PUBLIC_URL', 'http://localhost:8080/hls') . '/challenge_' . $challenge->id . '.m3u8';
            
            // Activer l'enregistrement et le live
            $challenge->creator_screen_recording = true;
            $challenge->is_live = true;
            $challenge->stream_key = $streamKey;
            $challenge->rtmp_url = $rtmpUrl . '/' . $streamKey;
            $challenge->stream_url = $publicStreamUrl;
            $challenge->live_started_at = now();
            $challenge->viewer_count = 0;
            
            // Generate a unique stream URL for screen recording
            if (!$challenge->creator_screen_stream_url) {
                $challenge->creator_screen_stream_url = 'recording_' . $challenge->id . '_creator_' . time();
            }
            
            $challenge->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Live streaming started successfully',
                'data' => [
                    'recording' => true,
                    'is_live' => true,
                    'stream_key' => $streamKey,
                    'rtmp_url' => $challenge->rtmp_url,
                    'stream_url' => $challenge->stream_url,
                    'screen_stream_url' => $challenge->creator_screen_stream_url,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error starting screen recording: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Stop screen recording for a challenge
     */
    public function stopScreenRecording(Request $request, $id)
    {
        $user = $request->user();
        $challenge = Challenge::find($id);

        if (!$challenge) {
            return response()->json([
                'success' => false,
                'message' => 'Challenge not found'
            ], 404);
        }

        // Seul le créateur peut arrêter le live
        if ($challenge->creator_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Only the challenge creator can stop the live streaming'
            ], 403);
        }

        DB::beginTransaction();
        try {
            // Arrêter l'enregistrement et le live
            $challenge->creator_screen_recording = false;
            $challenge->is_live = false;
            $challenge->live_ended_at = now();
            $challenge->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Live streaming stopped successfully',
                'data' => [
                    'recording' => false,
                    'is_live' => false,
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error stopping live streaming: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get live stream information for a challenge (public)
     */
    public function getLiveStream(Request $request, $id)
    {
        $challenge = Challenge::with(['creator', 'opponent'])
            ->find($id);

        if (!$challenge) {
            return response()->json([
                'success' => false,
                'message' => 'Challenge not found'
            ], 404);
        }

        if (!$challenge->is_live) {
            return response()->json([
                'success' => false,
                'message' => 'This challenge is not currently live'
            ], 400);
        }

        // Incrémenter le nombre de viewers (optionnel, peut être fait côté frontend)
        // $challenge->increment('viewer_count');

        return response()->json([
            'success' => true,
            'data' => [
                'challenge' => $challenge,
                'stream_url' => $challenge->stream_url,
                'is_live' => $challenge->is_live,
                'viewer_count' => $challenge->viewer_count,
                'live_started_at' => $challenge->live_started_at,
            ]
        ]);
    }

    /**
     * Update viewer count (called by frontend periodically)
     */
    public function updateViewerCount(Request $request, $id)
    {
        $challenge = Challenge::find($id);

        if (!$challenge) {
            return response()->json([
                'success' => false,
                'message' => 'Challenge not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'viewer_count' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $challenge->viewer_count = $request->viewer_count;
        $challenge->save();

        return response()->json([
            'success' => true,
            'data' => [
                'viewer_count' => $challenge->viewer_count
            ]
        ]);
    }
}
