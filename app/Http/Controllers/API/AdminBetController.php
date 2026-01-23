<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Bet;
use App\Models\Wallet;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AdminBetController extends Controller
{
    /**
     * Liste tous les paris
     */
    public function index(Request $request)
    {
        $query = Bet::with(['gameMatch.game', 'user']);

        // Filtrer par statut si fourni
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filtrer par utilisateur si fourni
        if ($request->has('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        // Filtrer par match si fourni
        if ($request->has('game_match_id')) {
            $query->where('game_match_id', $request->game_match_id);
        }

        $bets = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $bets
        ]);
    }

    /**
     * Récupère un pari spécifique
     */
    public function show($id)
    {
        $bet = Bet::with(['gameMatch.game', 'user'])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $bet
        ]);
    }

    /**
     * Met à jour le statut d'un pari et crédite le compte si gagné
     */
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:won,lost,cancelled',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $bet = Bet::with(['user', 'gameMatch'])->findOrFail($id);
        $newStatus = $request->input('status');

        // Vérifier que le pari n'est pas déjà terminé
        if (in_array($bet->status, ['won', 'lost', 'cancelled'])) {
            return response()->json([
                'success' => false,
                'message' => 'Ce pari a déjà été traité'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $bet->status = $newStatus;
            $bet->save();

            // Si le pari est gagné, créditer le compte de l'utilisateur
            if ($newStatus === 'won') {
                $wallet = Wallet::where('user_id', $bet->user_id)->first();
                
                if (!$wallet) {
                    // Créer le wallet s'il n'existe pas
                    $wallet = Wallet::create([
                        'user_id' => $bet->user_id,
                        'balance' => 0,
                        'locked_balance' => 0,
                        'currency' => 'USD',
                    ]);
                }

                // Créditer le gain
                $wallet->balance += $bet->potential_win;
                $wallet->save();

                // Créer une transaction pour le gain
                $transactionData = [
                    'wallet_id' => $wallet->id,
                    'user_id' => $bet->user_id,
                    'type' => 'win',
                    'amount' => $bet->potential_win,
                    'status' => 'confirmed',
                    'provider' => 'bet_win',
                    'txid' => 'BET_WIN_' . $bet->id . '_' . now()->format('YmdHis'),
                ];
                
                // Ajouter meta seulement si la colonne existe
                if (Schema::hasColumn('transactions', 'meta')) {
                    $transactionData['meta'] = [
                        'bet_id' => $bet->id,
                        'bet_type' => $bet->bet_type,
                        'game_match_id' => $bet->game_match_id,
                    ];
                }
                
                Transaction::create($transactionData);
            }

            // Si le pari est annulé, rembourser le montant
            if ($newStatus === 'cancelled') {
                $wallet = Wallet::where('user_id', $bet->user_id)->first();
                
                if (!$wallet) {
                    // Créer le wallet s'il n'existe pas
                    $wallet = Wallet::create([
                        'user_id' => $bet->user_id,
                        'balance' => 0,
                        'locked_balance' => 0,
                        'currency' => 'USD',
                    ]);
                }

                // Rembourser le montant
                $oldBalance = $wallet->balance;
                // Utiliser l'incrément pour éviter les problèmes de précision
                $wallet->increment('balance', $bet->amount);
                
                // Recharger le wallet pour obtenir la valeur mise à jour
                $wallet->refresh();

                // Créer une transaction pour le remboursement
                $refundTransactionData = [
                    'wallet_id' => $wallet->id,
                    'user_id' => $bet->user_id,
                    'type' => 'deposit',
                    'amount' => $bet->amount,
                    'status' => 'confirmed',
                    'provider' => 'bet_refund',
                    'txid' => 'BET_REFUND_' . $bet->id . '_' . now()->format('YmdHis'),
                ];
                
                // Ajouter meta seulement si la colonne existe
                if (Schema::hasColumn('transactions', 'meta')) {
                    $refundTransactionData['meta'] = [
                        'bet_id' => $bet->id,
                        'reason' => 'Bet cancelled',
                    ];
                }
                
                Transaction::create($refundTransactionData);
            }

            DB::commit();

            // Préparer le message de réponse
            $message = '';
            $walletBalance = null;
            
            if ($newStatus === 'won') {
                $wallet->refresh();
                $message = 'Pari marqué comme gagné et compte crédité avec succès. Gain: $' . number_format($bet->potential_win, 2);
                $walletBalance = $wallet->balance;
            } elseif ($newStatus === 'cancelled') {
                $wallet->refresh();
                $message = 'Pari annulé et montant remboursé. Montant remboursé: $' . number_format($bet->amount, 2) . '. Nouveau solde: $' . number_format($wallet->balance, 2);
                $walletBalance = $wallet->balance;
            } else {
                $message = 'Pari marqué comme perdu';
            }

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $bet->fresh(['gameMatch.game', 'user']),
                'wallet_balance' => $walletBalance,
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la mise à jour du pari: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supprime un pari (admin seulement)
     */
    public function destroy($id)
    {
        $bet = Bet::findOrFail($id);
        $bet->delete();

        return response()->json([
            'success' => true,
            'message' => 'Pari supprimé avec succès'
        ]);
    }
}

