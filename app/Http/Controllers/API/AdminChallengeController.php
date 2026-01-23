<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Challenge;
use App\Models\ChallengeMessage;
use App\Models\ChallengeStopRequest;
use App\Models\Wallet;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminChallengeController extends Controller
{
    /**
     * Liste tous les challenges avec filtres
     */
    public function index(Request $request)
    {
        $query = Challenge::with(['creator', 'opponent']);

        // Filtrer par statut
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filtrer les challenges en cours
        if ($request->has('active_only') && $request->active_only) {
            $query->whereIn('status', ['accepted', 'in_progress']);
        }

        // Recherche par jeu ou utilisateur
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('game', 'like', '%' . $search . '%')
                  ->orWhereHas('creator', function($q2) use ($search) {
                      $q2->where('username', 'like', '%' . $search . '%')
                         ->orWhere('email', 'like', '%' . $search . '%');
                  })
                  ->orWhereHas('opponent', function($q2) use ($search) {
                      $q2->where('username', 'like', '%' . $search . '%')
                         ->orWhere('email', 'like', '%' . $search . '%');
                  });
            });
        }

        $challenges = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $challenges
        ]);
    }

    /**
     * Récupère un challenge spécifique
     */
    public function show($id)
    {
        $challenge = Challenge::with(['creator', 'opponent', 'creator.wallet', 'opponent.wallet'])
            ->findOrFail($id);

        // Get all messages (including deleted ones for admin)
        $messages = ChallengeMessage::where('challenge_id', $challenge->id)
            ->with(['user:id,username'])
            ->orderBy('created_at', 'asc')
            ->get();

        // Statistics
        $stats = [
            'total_messages' => ChallengeMessage::where('challenge_id', $challenge->id)->count(),
            'active_messages' => ChallengeMessage::where('challenge_id', $challenge->id)->where('is_deleted', false)->count(),
            'deleted_messages' => ChallengeMessage::where('challenge_id', $challenge->id)->where('is_deleted', true)->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $challenge,
            'messages' => $messages,
            'stats' => $stats
        ]);
    }

    /**
     * Get challenge messages (admin can see all, including deleted)
     */
    public function getMessages($id)
    {
        $challenge = Challenge::findOrFail($id);

        $messages = ChallengeMessage::where('challenge_id', $challenge->id)
            ->with(['user:id,username'])
            ->orderBy('created_at', 'asc')
            ->paginate(100);

        return response()->json([
            'success' => true,
            'data' => $messages
        ]);
    }

    /**
     * Termine un challenge manuellement et définit le gagnant
     */
    public function completeChallenge(Request $request, $id)
    {
        $challenge = Challenge::with(['creator', 'opponent'])->findOrFail($id);

        if ($challenge->status === 'completed' || $challenge->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'This challenge cannot be completed'
            ], 400);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'winner_id' => 'required|in:creator,opponent,draw',
            'creator_score' => 'nullable|integer|min:0',
            'opponent_score' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Mettre à jour les scores si fournis
            if ($request->has('creator_score')) {
                $challenge->creator_score = $request->creator_score;
            }
            if ($request->has('opponent_score')) {
                $challenge->opponent_score = $request->opponent_score;
            }

            // Si les scores ne sont pas fournis, utiliser les scores existants ou 0
            if ($challenge->creator_score === null) {
                $challenge->creator_score = 0;
            }
            if ($challenge->opponent_score === null) {
                $challenge->opponent_score = 0;
            }

            // Déterminer le gagnant selon la requête de l'admin
            $winner = $request->winner_id;
            
            // Si draw, les scores doivent être égaux
            if ($winner === 'draw') {
                $challenge->creator_score = $challenge->opponent_score;
            } elseif ($winner === 'creator') {
                $challenge->creator_score = max($challenge->creator_score, $challenge->opponent_score + 1);
            } elseif ($winner === 'opponent') {
                $challenge->opponent_score = max($challenge->opponent_score, $challenge->creator_score + 1);
            }

            // Distribuer les gains
            $this->distributeWinnings($challenge, $winner);

            // Marquer comme complété
            $challenge->status = 'completed';
            $challenge->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Challenge completed successfully',
                'data' => $challenge->load(['creator', 'opponent'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error completing challenge: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Annule un challenge (admin)
     */
    public function cancelChallenge(Request $request, $id)
    {
        $challenge = Challenge::with(['creator', 'opponent'])->findOrFail($id);

        if ($challenge->status === 'completed' || $challenge->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'This challenge cannot be cancelled'
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Rembourser les deux joueurs
            $creatorWallet = Wallet::where('user_id', $challenge->creator_id)->first();
            $opponentWallet = $challenge->opponent_id ? Wallet::where('user_id', $challenge->opponent_id)->first() : null;

            if ($creatorWallet) {
                $creatorWallet->balance += $challenge->bet_amount;
                $creatorWallet->locked_balance -= $challenge->bet_amount;
                if ($creatorWallet->locked_balance < 0) {
                    $creatorWallet->locked_balance = 0;
                }
                $creatorWallet->save();

                // Créer une transaction pour le remboursement
                if (Schema::hasColumn('transactions', 'meta')) {
                    $creatorWallet->user->transactions()->create([
                        'wallet_id' => $creatorWallet->id,
                        'type' => 'deposit',
                        'amount' => $challenge->bet_amount,
                        'status' => 'confirmed',
                        'provider' => 'challenge_refund',
                        'txid' => 'CHALLENGE_REFUND_' . $challenge->id . '_' . $challenge->creator_id . '_' . now()->format('YmdHis'),
                        'meta' => json_encode(['challenge_id' => $challenge->id, 'reason' => 'admin_cancellation'])
                    ]);
                } else {
                    $creatorWallet->user->transactions()->create([
                        'wallet_id' => $creatorWallet->id,
                        'type' => 'deposit',
                        'amount' => $challenge->bet_amount,
                        'status' => 'confirmed',
                        'provider' => 'challenge_refund',
                        'txid' => 'CHALLENGE_REFUND_' . $challenge->id . '_' . $challenge->creator_id . '_' . now()->format('YmdHis'),
                    ]);
                }
            }

            if ($opponentWallet) {
                $opponentWallet->balance += $challenge->bet_amount;
                $opponentWallet->locked_balance -= $challenge->bet_amount;
                if ($opponentWallet->locked_balance < 0) {
                    $opponentWallet->locked_balance = 0;
                }
                $opponentWallet->save();

                // Créer une transaction pour le remboursement
                if (Schema::hasColumn('transactions', 'meta')) {
                    $opponentWallet->user->transactions()->create([
                        'wallet_id' => $opponentWallet->id,
                        'type' => 'deposit',
                        'amount' => $challenge->bet_amount,
                        'status' => 'confirmed',
                        'provider' => 'challenge_refund',
                        'txid' => 'CHALLENGE_REFUND_' . $challenge->id . '_' . $challenge->opponent_id . '_' . now()->format('YmdHis'),
                        'meta' => json_encode(['challenge_id' => $challenge->id, 'reason' => 'admin_cancellation'])
                    ]);
                } else {
                    $opponentWallet->user->transactions()->create([
                        'wallet_id' => $opponentWallet->id,
                        'type' => 'deposit',
                        'amount' => $challenge->bet_amount,
                        'status' => 'confirmed',
                        'provider' => 'challenge_refund',
                        'txid' => 'CHALLENGE_REFUND_' . $challenge->id . '_' . $challenge->opponent_id . '_' . now()->format('YmdHis'),
                    ]);
                }
            }

            // Marquer comme annulé
            $challenge->status = 'cancelled';
            $challenge->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Challenge cancelled successfully',
                'data' => $challenge->load(['creator', 'opponent'])
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
     * Distribue les gains après complétion d'un challenge
     */
    private function distributeWinnings(Challenge $challenge, $winner)
    {
        $totalPot = $challenge->bet_amount * 2; // Les deux joueurs ont misé le même montant
        
        $creatorWallet = Wallet::where('user_id', $challenge->creator_id)->first();
        $opponentWallet = $challenge->opponent_id ? Wallet::where('user_id', $challenge->opponent_id)->first() : null;

        if (!$creatorWallet || !$opponentWallet) {
            throw new \Exception('Wallet not found for one of the players');
        }

        // Débloquer les montants misés
        $creatorWallet->locked_balance -= $challenge->bet_amount;
        $opponentWallet->locked_balance -= $challenge->bet_amount;

        if ($creatorWallet->locked_balance < 0) {
            $creatorWallet->locked_balance = 0;
        }
        if ($opponentWallet->locked_balance < 0) {
            $opponentWallet->locked_balance = 0;
        }

        if ($winner === 'creator') {
            // Creator gagne
            $creatorWallet->balance += $totalPot;
            
            // Créer transaction pour le gagnant
            if (Schema::hasColumn('transactions', 'meta')) {
                $creatorWallet->user->transactions()->create([
                    'wallet_id' => $creatorWallet->id,
                    'type' => 'deposit',
                    'amount' => $totalPot,
                    'status' => 'confirmed',
                    'provider' => 'challenge_win',
                    'txid' => 'CHALLENGE_WIN_' . $challenge->id . '_' . $challenge->creator_id . '_' . now()->format('YmdHis'),
                    'meta' => json_encode(['challenge_id' => $challenge->id, 'opponent_id' => $challenge->opponent_id])
                ]);
            } else {
                $creatorWallet->user->transactions()->create([
                    'wallet_id' => $creatorWallet->id,
                    'type' => 'deposit',
                    'amount' => $totalPot,
                    'status' => 'confirmed',
                    'provider' => 'challenge_win',
                    'txid' => 'CHALLENGE_WIN_' . $challenge->id . '_' . $challenge->creator_id . '_' . now()->format('YmdHis'),
                ]);
            }

            // Créer transaction pour le perdant
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

        } elseif ($winner === 'opponent') {
            // Opponent gagne
            $opponentWallet->balance += $totalPot;
            
            // Créer transaction pour le gagnant
            if (Schema::hasColumn('transactions', 'meta')) {
                $opponentWallet->user->transactions()->create([
                    'wallet_id' => $opponentWallet->id,
                    'type' => 'deposit',
                    'amount' => $totalPot,
                    'status' => 'confirmed',
                    'provider' => 'challenge_win',
                    'txid' => 'CHALLENGE_WIN_' . $challenge->id . '_' . $challenge->opponent_id . '_' . now()->format('YmdHis'),
                    'meta' => json_encode(['challenge_id' => $challenge->id, 'opponent_id' => $challenge->creator_id])
                ]);
            } else {
                $opponentWallet->user->transactions()->create([
                    'wallet_id' => $opponentWallet->id,
                    'type' => 'deposit',
                    'amount' => $totalPot,
                    'status' => 'confirmed',
                    'provider' => 'challenge_win',
                    'txid' => 'CHALLENGE_WIN_' . $challenge->id . '_' . $challenge->opponent_id . '_' . now()->format('YmdHis'),
                ]);
            }

            // Créer transaction pour le perdant
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
            // Draw - rembourser les deux joueurs
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
    }

    /**
     * Get all pending stop requests
     */
    public function getStopRequests(Request $request)
    {
        $query = ChallengeStopRequest::with([
            'challenge:id,game,bet_amount,status,creator_id,opponent_id',
            'challenge.creator:id,username',
            'challenge.opponent:id,username',
            'initiator:id,username',
            'confirmer:id,username'
        ])
        ->where('status', 'confirmed')
        ->orderBy('confirmed_at', 'desc');

        // Get all without pagination for admin view
        $requests = $query->get();

        return response()->json([
            'success' => true,
            'data' => $requests
        ]);
    }

    /**
     * Approve stop request and distribute winnings
     */
    public function approveStopRequest(Request $request, $id)
    {
        $user = $request->user();
        $stopRequest = ChallengeStopRequest::with(['challenge'])->findOrFail($id);

        if ($stopRequest->status !== 'confirmed') {
            return response()->json([
                'success' => false,
                'message' => 'Stop request must be confirmed by both players before approval.'
            ], 400);
        }

        $challenge = $stopRequest->challenge;

        if ($challenge->status === 'completed' || $challenge->status === 'cancelled') {
            return response()->json([
                'success' => false,
                'message' => 'Challenge is already completed or cancelled.'
            ], 400);
        }

        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'winner_id' => 'required|in:creator,opponent,draw',
            'creator_score' => 'nullable|integer|min:0',
            'opponent_score' => 'nullable|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Update scores if provided
            if ($request->has('creator_score')) {
                $challenge->creator_score = $request->creator_score;
            }
            if ($request->has('opponent_score')) {
                $challenge->opponent_score = $request->opponent_score;
            }

            // If scores not provided, use existing or 0
            if ($challenge->creator_score === null) {
                $challenge->creator_score = 0;
            }
            if ($challenge->opponent_score === null) {
                $challenge->opponent_score = 0;
            }

            // Determine winner
            $winner = $request->winner_id;
            
            if ($winner === 'draw') {
                $challenge->creator_score = $challenge->opponent_score;
            } elseif ($winner === 'creator') {
                $challenge->creator_score = max($challenge->creator_score, $challenge->opponent_score + 1);
            } elseif ($winner === 'opponent') {
                $challenge->opponent_score = max($challenge->opponent_score, $challenge->creator_score + 1);
            }

            // Distribute winnings
            $this->distributeWinnings($challenge, $winner);

            // Update challenge status
            $challenge->status = 'completed';
            $challenge->save();

            // Update stop request
            $stopRequest->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $user->id,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Stop request approved and challenge completed successfully',
                'data' => $challenge->load(['creator', 'opponent'])
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error approving stop request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject stop request
     */
    public function rejectStopRequest(Request $request, $id)
    {
        $stopRequest = ChallengeStopRequest::findOrFail($id);

        if ($stopRequest->status !== 'confirmed') {
            return response()->json([
                'success' => false,
                'message' => 'Can only reject confirmed stop requests.'
            ], 400);
        }

        $stopRequest->update([
            'status' => 'rejected',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Stop request rejected successfully'
        ]);
    }
}

