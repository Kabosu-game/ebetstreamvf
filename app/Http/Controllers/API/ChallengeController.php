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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChallengeController extends Controller
{
    /**
     * Liste publique des challenges avec filtres.
     */
    public function index(Request $request)
    {
        $query = Challenge::with(['creator', 'opponent', 'creatorClan', 'opponentClan']);

        // Filtre par type (user ou clan)
        if ($request->has('type')) {
            if ($request->type === 'clan') {
                $query->clanChallenges();
            } elseif ($request->type === 'user') {
                $query->userChallenges();
            }
        }

        // Filtre par statut
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Challenges ouverts seulement
        if ($request->boolean('open_only')) {
            $query->open();
        }

        // Filtre par jeu
        if ($request->filled('game')) {
            $query->where('game', 'like', '%' . $request->game . '%');
        }

        // Challenges de l'utilisateur connecté
        if ($request->boolean('my_challenges')) {
            $user = $request->user();
            $query->where(function ($q) use ($user) {
                $q->forUser($user->id)
                    ->orWhereHas('creatorClan', function ($clanQuery) use ($user) {
                        $clanQuery->whereHas('members', fn($m) => $m->where('user_id', $user->id));
                    })
                    ->orWhereHas('opponentClan', function ($clanQuery) use ($user) {
                        $clanQuery->whereHas('members', fn($m) => $m->where('user_id', $user->id));
                    });
            });
        }

        // Filtre par clan
        if ($request->filled('clan_id')) {
            $query->forClan($request->clan_id);
        }

        $challenges = $query->orderBy('created_at', 'desc')->paginate($request->integer('per_page', 12));

        return response()->json([
            'success' => true,
            'data'    => $challenges,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate')
          ->header('Pragma', 'no-cache');
    }

    /**
     * Détail d'un challenge.
     */
    public function show($id)
    {
        $challenge = Challenge::with(['creator', 'opponent', 'creatorClan', 'opponentClan'])->find($id);

        if (!$challenge) {
            return response()->json([
                'success' => false,
                'message' => 'Challenge non trouvé.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data'    => $challenge,
        ])->header('Cache-Control', 'no-store, no-cache, must-revalidate')
          ->header('Pragma', 'no-cache');
    }

    /**
     * Créer un nouveau challenge (entre utilisateurs).
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'game'              => 'required|string|max:255',
            'bet_amount'        => 'required|numeric|min:500|max:1000000',
            'expires_at'        => 'nullable|date|after:now',
            'opponent_username' => 'nullable|string|exists:users,username',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Gestion de l'adversaire direct
        $opponent = null;
        if ($request->filled('opponent_username')) {
            $opponent = User::where('username', $request->opponent_username)->first();

            if (!$opponent) {
                return response()->json([
                    'success' => false,
                    'message' => 'Utilisateur adverse non trouvé.',
                ], 404);
            }

            if ($opponent->id === $user->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous ne pouvez pas vous défier vous-même.',
                ], 400);
            }

            // Vérifier que l'adversaire a assez de fonds disponibles
            $opponentWallet = Wallet::where('user_id', $opponent->id)->first();
            if (!$opponentWallet) {
                return response()->json([
                    'success' => false,
                    'message' => 'Portefeuille de l\'adversaire introuvable.',
                ], 404);
            }

            $opponentAvailable = $opponentWallet->balance - $opponentWallet->locked_balance;
            if ($opponentAvailable < $request->bet_amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'L\'adversaire n\'a pas assez de fonds disponibles.',
                ], 400);
            }
        }

        // Vérifier le solde du créateur
        $wallet = Wallet::where('user_id', $user->id)->first();
        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Portefeuille introuvable.',
            ], 404);
        }

        $available = $wallet->balance - $wallet->locked_balance;
        if ($available < $request->bet_amount) {
            return response()->json([
                'success' => false,
                'message' => 'Solde insuffisant pour créer ce défi.',
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Verrouiller le montant du créateur
            $wallet->locked_balance += $request->bet_amount;
            $wallet->save();

            // Si adversaire direct, verrouiller aussi son montant
            if ($opponent) {
                $opponentWallet = Wallet::where('user_id', $opponent->id)->first();
                $opponentWallet->locked_balance += $request->bet_amount;
                $opponentWallet->save();
            }

            $challenge = Challenge::create([
                'type'         => 'user',
                'creator_id'   => $user->id,
                'opponent_id'  => $opponent?->id,
                'game'         => $request->game,
                'bet_amount'   => $request->bet_amount,
                'status'       => $opponent ? 'accepted' : 'open',
                'expires_at'   => $request->expires_at ?? now()->addDays(7),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $opponent ? 'Défi direct créé et accepté.' : 'Défi créé avec succès.',
                'data'    => $challenge->load(['creator', 'opponent']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[Challenge::store] ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du défi.',
            ], 500);
        }
    }

    /**
     * Accepter un défi ouvert.
     */
    public function accept(Request $request, $id)
    {
        $user = $request->user();
        $challenge = Challenge::find($id);

        if (!$challenge) {
            return response()->json([
                'success' => false,
                'message' => 'Défi non trouvé.',
            ], 404);
        }

        if ($challenge->status !== 'open') {
            return response()->json([
                'success' => false,
                'message' => 'Ce défi n\'est pas ouvert à l\'acceptation.',
            ], 400);
        }

        if ($challenge->creator_id === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez pas accepter votre propre défi.',
            ], 400);
        }

        if ($challenge->expires_at && $challenge->expires_at < now()) {
            return response()->json([
                'success' => false,
                'message' => 'Ce défi a expiré.',
            ], 400);
        }

        // Vérifier le solde de l'accepteur
        $wallet = Wallet::where('user_id', $user->id)->first();
        if (!$wallet) {
            return response()->json([
                'success' => false,
                'message' => 'Portefeuille introuvable.',
            ], 404);
        }

        $available = $wallet->balance - $wallet->locked_balance;
        if ($available < $challenge->bet_amount) {
            return response()->json([
                'success' => false,
                'message' => 'Solde insuffisant pour accepter ce défi.',
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Verrouiller le montant de l'accepteur
            $wallet->locked_balance += $challenge->bet_amount;
            $wallet->save();

            $challenge->opponent_id = $user->id;
            $challenge->status = 'accepted';
            $challenge->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Défi accepté avec succès.',
                'data'    => $challenge->load(['creator', 'opponent']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[Challenge::accept] ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'acceptation du défi.',
            ], 500);
        }
    }

    /**
     * Annuler un défi (uniquement s'il est ouvert).
     */
    public function cancel(Request $request, $id)
    {
        $user = $request->user();
        $challenge = Challenge::find($id);

        if (!$challenge) {
            return response()->json([
                'success' => false,
                'message' => 'Défi non trouvé.',
            ], 404);
        }

        if ($challenge->creator_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Seul le créateur peut annuler ce défi.',
            ], 403);
        }

        if ($challenge->status !== 'open') {
            return response()->json([
                'success' => false,
                'message' => 'Ce défi ne peut plus être annulé.',
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Déverrouiller le montant du créateur
            $wallet = Wallet::where('user_id', $user->id)->first();
            if ($wallet) {
                $wallet->locked_balance -= $challenge->bet_amount;
                $wallet->save();
            }

            $challenge->status = 'cancelled';
            $challenge->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Défi annulé.',
                'data'    => $challenge,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[Challenge::cancel] ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'annulation.',
            ], 500);
        }
    }

    /**
     * Soumettre un score pour un défi accepté ou en cours.
     */
    public function submitScores(Request $request, $id)
    {
        $user = $request->user();
        $challenge = Challenge::find($id);

        if (!$challenge) {
            return response()->json([
                'success' => false,
                'message' => 'Défi non trouvé.',
            ], 404);
        }

        if (!in_array($challenge->status, ['accepted', 'in_progress'])) {
            return response()->json([
                'success' => false,
                'message' => 'Le défi n\'est pas dans un état permettant la soumission de score.',
            ], 400);
        }

        if ($challenge->creator_id !== $user->id && $challenge->opponent_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne faites pas partie de ce défi.',
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'score' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        DB::beginTransaction();
        try {
            if ($challenge->creator_id === $user->id) {
                $challenge->creator_score = $request->score;
            } else {
                $challenge->opponent_score = $request->score;
            }

            // Si les deux scores sont renseignés, terminer le défi
            if ($challenge->creator_score !== null && $challenge->opponent_score !== null) {
                // Arrêter le live si actif
                if ($challenge->is_live) {
                    $challenge->is_live = false;
                    $challenge->live_ended_at = now();
                }

                $challenge->status = 'completed';
                $challenge->save();

                // Distribuer les gains
                $this->distributeWinnings($challenge);
            } else {
                $challenge->status = 'in_progress';
                $challenge->save();
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Score soumis avec succès.',
                'data'    => $challenge->load(['creator', 'opponent']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[Challenge::submitScores] ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la soumission du score.',
            ], 500);
        }
    }

    /**
     * Distribution des gains après complétion du défi.
     */
    private function distributeWinnings(Challenge $challenge)
    {
        $creatorWallet = Wallet::where('user_id', $challenge->creator_id)->firstOrFail();
        $opponentWallet = Wallet::where('user_id', $challenge->opponent_id)->firstOrFail();

        // Déverrouiller les montants misés
        $creatorWallet->locked_balance -= $challenge->bet_amount;
        $opponentWallet->locked_balance -= $challenge->bet_amount;

        if ($creatorWallet->locked_balance < 0) $creatorWallet->locked_balance = 0;
        if ($opponentWallet->locked_balance < 0) $opponentWallet->locked_balance = 0;

        if ($challenge->creator_score > $challenge->opponent_score) {
            // Le créateur gagne : il reçoit la mise de l'adversaire
            $creatorWallet->balance += $challenge->bet_amount;

            // Transactions
            $creatorWallet->user->transactions()->create([
                'wallet_id' => $creatorWallet->id,
                'type'      => 'deposit',
                'amount'    => $challenge->bet_amount,
                'status'    => 'confirmed',
                'provider'  => 'challenge_win',
                'txid'      => 'CHALLENGE_WIN_' . $challenge->id . '_' . $challenge->creator_id . '_' . now()->format('YmdHis'),
                'meta'      => json_encode(['challenge_id' => $challenge->id, 'opponent_id' => $challenge->opponent_id]),
            ]);

            $opponentWallet->user->transactions()->create([
                'wallet_id' => $opponentWallet->id,
                'type'      => 'bet',
                'amount'    => $challenge->bet_amount,
                'status'    => 'confirmed',
                'provider'  => 'challenge_loss',
                'txid'      => 'CHALLENGE_LOSS_' . $challenge->id . '_' . $challenge->opponent_id . '_' . now()->format('YmdHis'),
                'meta'      => json_encode(['challenge_id' => $challenge->id, 'winner_id' => $challenge->creator_id]),
            ]);
        } elseif ($challenge->opponent_score > $challenge->creator_score) {
            // L'adversaire gagne
            $opponentWallet->balance += $challenge->bet_amount;

            $opponentWallet->user->transactions()->create([
                'wallet_id' => $opponentWallet->id,
                'type'      => 'deposit',
                'amount'    => $challenge->bet_amount,
                'status'    => 'confirmed',
                'provider'  => 'challenge_win',
                'txid'      => 'CHALLENGE_WIN_' . $challenge->id . '_' . $challenge->opponent_id . '_' . now()->format('YmdHis'),
                'meta'      => json_encode(['challenge_id' => $challenge->id, 'opponent_id' => $challenge->creator_id]),
            ]);

            $creatorWallet->user->transactions()->create([
                'wallet_id' => $creatorWallet->id,
                'type'      => 'bet',
                'amount'    => $challenge->bet_amount,
                'status'    => 'confirmed',
                'provider'  => 'challenge_loss',
                'txid'      => 'CHALLENGE_LOSS_' . $challenge->id . '_' . $challenge->creator_id . '_' . now()->format('YmdHis'),
                'meta'      => json_encode(['challenge_id' => $challenge->id, 'winner_id' => $challenge->opponent_id]),
            ]);
        } else {
            // Égalité : remboursement des deux
            $creatorWallet->balance += $challenge->bet_amount;
            $opponentWallet->balance += $challenge->bet_amount;

            $creatorWallet->user->transactions()->create([
                'wallet_id' => $creatorWallet->id,
                'type'      => 'deposit',
                'amount'    => $challenge->bet_amount,
                'status'    => 'confirmed',
                'provider'  => 'challenge_draw',
                'txid'      => 'CHALLENGE_DRAW_' . $challenge->id . '_' . $challenge->creator_id . '_' . now()->format('YmdHis'),
                'meta'      => json_encode(['challenge_id' => $challenge->id, 'result' => 'draw']),
            ]);

            $opponentWallet->user->transactions()->create([
                'wallet_id' => $opponentWallet->id,
                'type'      => 'deposit',
                'amount'    => $challenge->bet_amount,
                'status'    => 'confirmed',
                'provider'  => 'challenge_draw',
                'txid'      => 'CHALLENGE_DRAW_' . $challenge->id . '_' . $challenge->opponent_id . '_' . now()->format('YmdHis'),
                'meta'      => json_encode(['challenge_id' => $challenge->id, 'result' => 'draw']),
            ]);
        }

        $creatorWallet->save();
        $opponentWallet->save();

        // Résoudre les paris tiers éventuels
        $this->resolveChallengeBets($challenge);
    }

    /**
     * Résolution des paris placés sur le défi.
     */
    private function resolveChallengeBets(Challenge $challenge)
    {
        if ($challenge->creator_score == $challenge->opponent_score) {
            // Égalité : remboursement de tous les paris
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

        $winner = $challenge->creator_score > $challenge->opponent_score ? 'creator_win' : 'opponent_win';

        $bets = Bet::where('challenge_id', $challenge->id)
            ->where('status', 'pending')
            ->with('user')
            ->get();

        foreach ($bets as $bet) {
            $wallet = Wallet::where('user_id', $bet->user_id)->first();
            if (!$wallet) continue;

            if ($bet->bet_type === $winner) {
                // Gain
                $wallet->balance += $bet->potential_win;
                $wallet->save();
                $bet->status = 'won';
            } else {
                $bet->status = 'lost';
            }
            $bet->save();
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // CHAT
    // ──────────────────────────────────────────────────────────────────────────

    public function getMessages(Request $request, $id)
    {
        $user = $request->user();
        $challenge = Challenge::findOrFail($id);

        if ($challenge->creator_id !== $user->id && $challenge->opponent_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Accès non autorisé.',
            ], 403);
        }

        $messages = ChallengeMessage::where('challenge_id', $challenge->id)
            ->where('is_deleted', false)
            ->with(['user:id,username'])
            ->latest()
            ->paginate(50);

        return response()->json([
            'success' => true,
            'data'    => $messages,
        ]);
    }

    public function sendMessage(Request $request, $id)
    {
        $user = $request->user();
        $challenge = Challenge::findOrFail($id);

        if ($challenge->creator_id !== $user->id && $challenge->opponent_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Seuls le créateur et l\'adversaire peuvent envoyer des messages.',
            ], 403);
        }

        if (!$challenge->opponent_id) {
            return response()->json([
                'success' => false,
                'message' => 'Le défi doit être accepté avant de pouvoir chatter.',
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'message' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $message = ChallengeMessage::create([
            'challenge_id' => $challenge->id,
            'user_id'      => $user->id,
            'message'      => $request->message,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Message envoyé.',
            'data'    => $message->load('user:id,username'),
        ], 201);
    }

    public function deleteMessage(Request $request, $id, $messageId)
    {
        $user = $request->user();
        $challenge = Challenge::findOrFail($id);

        $message = ChallengeMessage::where('challenge_id', $challenge->id)
            ->findOrFail($messageId);

        if ($message->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Vous ne pouvez supprimer que vos propres messages.',
            ], 403);
        }

        $message->update(['is_deleted' => true]);

        return response()->json([
            'success' => true,
            'message' => 'Message supprimé.',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // DEMANDE D'ARRÊT (STOP REQUEST)
    // ──────────────────────────────────────────────────────────────────────────

    public function requestStop(Request $request, $id)
    {
        $user = $request->user();
        $challenge = Challenge::findOrFail($id);

        if ($challenge->creator_id !== $user->id && $challenge->opponent_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Seuls les participants peuvent demander l\'arrêt.',
            ], 403);
        }

        if (!in_array($challenge->status, ['accepted', 'in_progress'])) {
            return response()->json([
                'success' => false,
                'message' => 'Le défi doit être accepté ou en cours.',
            ], 400);
        }

        if (!$challenge->opponent_id) {
            return response()->json([
                'success' => false,
                'message' => 'Le défi doit avoir un adversaire.',
            ], 400);
        }

        $existing = ChallengeStopRequest::where('challenge_id', $challenge->id)
            ->whereIn('status', ['pending', 'confirmed'])
            ->first();

        if ($existing) {
            if ($existing->initiator_id !== $user->id) {
                // L'autre joueur confirme
                $existing->update([
                    'confirmer_id' => $user->id,
                    'status'       => 'confirmed',
                    'confirmed_at' => now(),
                ]);
                return response()->json([
                    'success' => true,
                    'message' => 'Demande d\'arrêt confirmée. En attente de validation admin.',
                    'data'    => $existing->load(['initiator:id,username', 'confirmer:id,username']),
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous avez déjà fait une demande. Attendez la confirmation de l\'adversaire.',
                ], 400);
            }
        }

        $stopRequest = ChallengeStopRequest::create([
            'challenge_id' => $challenge->id,
            'initiator_id' => $user->id,
            'status'       => 'pending',
            'reason'       => $request->reason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Demande d\'arrêt créée. En attente de confirmation de l\'adversaire.',
            'data'    => $stopRequest->load('initiator:id,username'),
        ], 201);
    }

    public function getStopRequest(Request $request, $id)
    {
        $user = $request->user();
        $challenge = Challenge::findOrFail($id);

        if ($challenge->creator_id !== $user->id && $challenge->opponent_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Accès non autorisé.',
            ], 403);
        }

        $stopRequest = ChallengeStopRequest::where('challenge_id', $challenge->id)
            ->with(['initiator:id,username', 'confirmer:id,username'])
            ->first();

        return response()->json([
            'success' => true,
            'data'    => $stopRequest,
        ]);
    }

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
                'message' => 'Aucune demande en attente.',
            ], 404);
        }

        if ($stopRequest->initiator_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Seul l\'initiateur peut annuler la demande.',
            ], 403);
        }

        $stopRequest->delete();

        return response()->json([
            'success' => true,
            'message' => 'Demande d\'arrêt annulée.',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // DÉFIS ENTRE CLANS
    // ──────────────────────────────────────────────────────────────────────────

    public function storeClanChallenge(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'game'             => 'required|string|max:255',
            'bet_amount'       => 'required|numeric|min:500|max:1000000',
            'expires_at'       => 'nullable|date|after:now',
            'opponent_clan_id' => 'nullable|integer|exists:clans,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $creatorClan = Clan::whereHas('members', fn($q) => $q->where('user_id', $user->id))->first();
        if (!$creatorClan) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être membre d\'un clan pour créer un défi de clan.',
            ], 400);
        }

        $opponentClan = null;
        if ($request->filled('opponent_clan_id')) {
            $opponentClan = Clan::find($request->opponent_clan_id);
            if ($opponentClan->id === $creatorClan->id) {
                return response()->json([
                    'success' => false,
                    'message' => 'Vous ne pouvez pas défier votre propre clan.',
                ], 400);
            }
        }

        $wallet = Wallet::where('user_id', $user->id)->firstOrFail();
        $available = $wallet->balance - $wallet->locked_balance;
        if ($available < $request->bet_amount) {
            return response()->json([
                'success' => false,
                'message' => 'Solde insuffisant.',
            ], 400);
        }

        DB::beginTransaction();
        try {
            $wallet->locked_balance += $request->bet_amount;
            $wallet->save();

            $challenge = Challenge::create([
                'type'            => 'clan',
                'creator_id'      => $user->id,
                'creator_clan_id' => $creatorClan->id,
                'opponent_clan_id' => $opponentClan?->id,
                'game'            => $request->game,
                'bet_amount'      => $request->bet_amount,
                'status'          => $opponentClan ? 'accepted' : 'open',
                'expires_at'      => $request->expires_at ?? now()->addDays(7),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => $opponentClan ? 'Défi de clan créé et accepté.' : 'Défi de clan créé.',
                'data'    => $challenge->load(['creator', 'creatorClan', 'opponentClan']),
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[Challenge::storeClanChallenge] ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la création du défi de clan.',
            ], 500);
        }
    }

    public function acceptClanChallenge(Request $request, $id)
    {
        $user = $request->user();
        $challenge = Challenge::where('type', 'clan')->find($id);

        if (!$challenge) {
            return response()->json([
                'success' => false,
                'message' => 'Défi de clan non trouvé.',
            ], 404);
        }

        if ($challenge->status !== 'open') {
            return response()->json([
                'success' => false,
                'message' => 'Ce défi n\'est pas ouvert.',
            ], 400);
        }

        if (!$challenge->opponent_clan_id) {
            return response()->json([
                'success' => false,
                'message' => 'Ce défi n\'a pas de clan adversaire spécifié.',
            ], 400);
        }

        $opponentClan = Clan::find($challenge->opponent_clan_id);
        if (!$opponentClan || !$opponentClan->isMember($user->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être membre du clan adverse pour accepter ce défi.',
            ], 403);
        }

        if ($challenge->expires_at && $challenge->expires_at < now()) {
            return response()->json([
                'success' => false,
                'message' => 'Ce défi a expiré.',
            ], 400);
        }

        $wallet = Wallet::where('user_id', $user->id)->firstOrFail();
        $available = $wallet->balance - $wallet->locked_balance;
        if ($available < $challenge->bet_amount) {
            return response()->json([
                'success' => false,
                'message' => 'Solde insuffisant.',
            ], 400);
        }

        DB::beginTransaction();
        try {
            $wallet->locked_balance += $challenge->bet_amount;
            $wallet->save();

            $challenge->opponent_id = $user->id; // Le membre qui accepte représente le clan
            $challenge->status = 'accepted';
            $challenge->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Défi de clan accepté.',
                'data'    => $challenge->load(['creator', 'opponent', 'creatorClan', 'opponentClan']),
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[Challenge::acceptClanChallenge] ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'acceptation.',
            ], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // LIVE STREAMING (screen recording)
    // ──────────────────────────────────────────────────────────────────────────

    public function startScreenRecording(Request $request, $id)
    {
        $user = $request->user();
        $challenge = Challenge::findOrFail($id);

        if ($challenge->creator_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Seul le créateur peut démarrer le live.',
            ], 403);
        }

        if (!in_array($challenge->status, ['accepted', 'in_progress'])) {
            return response()->json([
                'success' => false,
                'message' => 'Le défi doit être accepté ou en cours.',
            ], 400);
        }

        if ($challenge->is_live) {
            return response()->json([
                'success' => false,
                'message' => 'Le live est déjà actif.',
            ], 400);
        }

        DB::beginTransaction();
        try {
            $streamKey = 'challenge_' . $challenge->id . '_' . time() . '_' . bin2hex(random_bytes(8));
            $rtmpUrl = rtrim(env('RTMP_SERVER_URL', 'rtmp://localhost:1935/live'), '/');
            $publicUrl = env('STREAM_PUBLIC_URL', 'http://localhost:8080/hls') . '/challenge_' . $challenge->id . '.m3u8';

            $challenge->creator_screen_recording = true;
            $challenge->is_live = true;
            $challenge->stream_key = $streamKey;
            $challenge->rtmp_url = $rtmpUrl . '/' . $streamKey;
            $challenge->stream_url = $publicUrl;
            $challenge->live_started_at = now();
            $challenge->viewer_count = 0;
            $challenge->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Live démarré.',
                'data'    => [
                    'stream_key' => $streamKey,
                    'rtmp_url'   => $challenge->rtmp_url,
                    'stream_url' => $challenge->stream_url,
                    'is_live'    => true,
                ],
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('[Challenge::startScreenRecording] ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Erreur au démarrage du live.',
            ], 500);
        }
    }

    public function stopScreenRecording(Request $request, $id)
    {
        $user = $request->user();
        $challenge = Challenge::findOrFail($id);

        if ($challenge->creator_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Seul le créateur peut arrêter le live.',
            ], 403);
        }

        if (!$challenge->is_live) {
            return response()->json([
                'success' => false,
                'message' => 'Le live n\'est pas actif.',
            ], 400);
        }

        $challenge->creator_screen_recording = false;
        $challenge->is_live = false;
        $challenge->live_ended_at = now();
        $challenge->save();

        return response()->json([
            'success' => true,
            'message' => 'Live arrêté.',
            'data'    => ['is_live' => false],
        ]);
    }

    public function pauseScreenRecording(Request $request, $id)
    {
        $user = $request->user();
        $challenge = Challenge::findOrFail($id);

        if ($challenge->creator_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Seul le créateur peut mettre en pause.',
            ], 403);
        }

        if (!$challenge->is_live) {
            return response()->json([
                'success' => false,
                'message' => 'Le live n\'est pas actif.',
            ], 400);
        }

        $challenge->is_live_paused = true;
        $challenge->save();

        return response()->json([
            'success' => true,
            'message' => 'Live en pause.',
            'data'    => ['is_live_paused' => true],
        ]);
    }

    public function resumeScreenRecording(Request $request, $id)
    {
        $user = $request->user();
        $challenge = Challenge::findOrFail($id);

        if ($challenge->creator_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Seul le créateur peut reprendre.',
            ], 403);
        }

        if (!$challenge->is_live) {
            return response()->json([
                'success' => false,
                'message' => 'Le live n\'est pas actif.',
            ], 400);
        }

        $challenge->is_live_paused = false;
        $challenge->save();

        return response()->json([
            'success' => true,
            'message' => 'Live repris.',
            'data'    => ['is_live_paused' => false],
        ]);
    }

    public function updateViewerCount(Request $request, $id)
    {
        $challenge = Challenge::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'viewer_count' => 'required|integer|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        $challenge->viewer_count = $request->viewer_count;
        $challenge->save();

        return response()->json([
            'success' => true,
            'data'    => ['viewer_count' => $challenge->viewer_count],
        ]);
    }

    public function getLiveStream(Request $request, $id)
    {
        $challenge = Challenge::with(['creator', 'opponent'])->find($id);

        if (!$challenge) {
            return response()->json([
                'success' => false,
                'message' => 'Défi non trouvé.',
            ], 404);
        }

        if (!$challenge->is_live) {
            return response()->json([
                'success' => false,
                'message' => 'Ce défi n\'est pas en live actuellement.',
            ], 400);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'challenge'       => $challenge,
                'stream_url'      => $challenge->stream_url,
                'is_live'         => $challenge->is_live,
                'is_live_paused'  => $challenge->is_live_paused ?? false,
                'viewer_count'    => $challenge->viewer_count,
                'live_started_at' => $challenge->live_started_at,
            ],
        ]);
    }

    public function liveChallenges(Request $request)
    {
        $challenges = Challenge::with(['creator', 'opponent'])
            ->where('is_live', true)
            ->whereIn('status', ['accepted', 'in_progress'])
            ->orderBy('live_started_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => $challenges,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PARIS (BETS)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Récupère la liste des paris placés sur un challenge.
     * Accessible publiquement (lecture seule).
     */
    public function getChallengeBets(Request $request, $id)
    {
        $challenge = Challenge::find($id);

        if (!$challenge) {
            return response()->json([
                'success' => false,
                'message' => 'Défi non trouvé.',
            ], 404);
        }

        // Seuls les défis acceptés, en cours ou terminés exposent leurs paris
        if (!in_array($challenge->status, ['accepted', 'in_progress', 'completed'])) {
            return response()->json([
                'success' => false,
                'message' => 'Les paris ne sont pas encore visibles pour ce défi.',
            ], 400);
        }

        $bets = Bet::where('challenge_id', $challenge->id)
            ->with('user:id,username')
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'success' => true,
            'data'    => $bets,
        ]);
    }
}
