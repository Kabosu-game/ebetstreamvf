<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Championship;
use App\Models\ChampionshipRegistration;
use App\Models\ChampionshipMatch;
use App\Models\User;
use App\Models\Wallet;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;

class ChampionshipController extends Controller
{
    /**
     * Get all active championships (public)
     */
    public function index(Request $request)
    {
        $query = Championship::where('is_active', true)
            ->withCount(['validatedRegistrations as validated_registrations_count'])
            ->orderBy('division', 'asc')
            ->orderBy('start_date', 'asc');

        // Filter by game
        if ($request->has('game')) {
            $query->where('game', $request->game);
        }

        // Filter by division
        if ($request->has('division')) {
            $query->where('division', $request->division);
        }

        // Filter by status
        if ($request->has('status')) {
            $statuses = explode(',', $request->status);
            $query->whereIn('status', $statuses);
        }

        $championships = $query->get();

        return response()->json([
            'success' => true,
            'data' => $championships,
        ]);
    }

    /**
     * Get a specific championship
     */
    public function show($id)
    {
        try {
            $championship = Championship::with(['validatedRegistrations' => function($query) {
                $query->orderBy('points', 'desc')
                      ->orderBy('matches_won', 'desc')
                      ->with('user:id,username');
            }])
            ->withCount(['validatedRegistrations', 'matches'])
            ->findOrFail($id);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Championship not found'
            ], 404);
        } catch (\Exception $e) {
            \Log::error('Error loading championship: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Error loading championship: ' . $e->getMessage()
            ], 500);
        }

        // Load matches with players and odds
        try {
            $championship->load(['matches' => function($query) {
                $query->with([
                    'player1:id,championship_id,team_name,team_logo,full_name,player_username,user_id',
                    'player1.user:id,username,email',
                    'player2:id,championship_id,team_name,team_logo,full_name,player_username,user_id',
                    'player2.user:id,username,email'
                ])
                ->orderBy('round_number', 'asc')
                ->orderBy('scheduled_at', 'asc');
            }]);
        } catch (\Exception $e) {
            // If there's an error loading matches, just set empty array
            $championship->setRelation('matches', collect([]));
            \Log::error('Error loading championship matches: ' . $e->getMessage(), [
                'championship_id' => $id,
                'error' => $e->getTraceAsString()
            ]);
        }

        // Check if user is registered
        $user = auth('sanctum')->user();
        $isRegistered = false;
        $userRegistration = null;

        if ($user) {
            $userRegistration = ChampionshipRegistration::where('championship_id', $id)
                ->where('user_id', $user->id)
                ->first();
            $isRegistered = $userRegistration !== null;
        }

        return response()->json([
            'success' => true,
            'data' => $championship,
            'is_registered' => $isRegistered,
            'user_registration' => $userRegistration,
        ]);
    }

    /**
     * Register for a championship
     */
    public function register(Request $request, $id)
    {
        $user = auth('sanctum')->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Vous devez être connecté pour vous inscrire.',
            ], 401);
        }

        $championship = Championship::findOrFail($id);

        // Check if championship is active
        if (!$championship->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Ce championnat n\'est pas actif.',
            ], 400);
        }

        // Check status
        if (!in_array($championship->status, ['registration_open', 'draft'])) {
            return response()->json([
                'success' => false,
                'message' => 'Les inscriptions pour ce championnat ne sont pas ouvertes. Statut actuel: ' . $championship->status,
            ], 400);
        }

        // Check dates
        $now = Carbon::now()->startOfDay();
        $startDate = Carbon::parse($championship->registration_start_date)->startOfDay();
        $endDate = Carbon::parse($championship->registration_end_date)->endOfDay();
        
        if ($now->lt($startDate)) {
            return response()->json([
                'success' => false,
                'message' => 'Les inscriptions commenceront le ' . $startDate->format('d/m/Y'),
            ], 400);
        }
        
        if ($now->gt($endDate)) {
            return response()->json([
                'success' => false,
                'message' => 'Les inscriptions sont fermées depuis le ' . $endDate->format('d/m/Y'),
            ], 400);
        }

        // Check if registration is open (double check with method)
        if (!$championship->isRegistrationOpen()) {
            return response()->json([
                'success' => false,
                'message' => 'Les inscriptions pour ce championnat sont fermées.',
            ], 400);
        }

        // Check if already registered
        $existingRegistration = ChampionshipRegistration::where('championship_id', $id)
            ->where('user_id', $user->id)
            ->first();

        if ($existingRegistration) {
            return response()->json([
                'success' => false,
                'message' => 'Vous êtes déjà inscrit à ce championnat.',
            ], 400);
        }

        // Check if championship is full
        if ($championship->isFull()) {
            return response()->json([
                'success' => false,
                'message' => 'Ce championnat est complet.',
            ], 400);
        }

        // Parse players_list from JSON if it's a string (from FormData)
        if ($request->has('players_list') && is_string($request->players_list)) {
            $playersList = json_decode($request->players_list, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($playersList)) {
                $request->merge(['players_list' => $playersList]);
            }
        }

        // Validate form data
        $validated = $request->validate([
            'full_name' => 'required|string|max:255',
            'player_username' => 'required|string|max:255',
            'contact_email' => 'required|email|max:255',
            'team_name' => 'required|string|max:255',
            'team_logo' => 'nullable|image|max:5120',
            'players_list' => 'required|array|min:1',
            'players_list.*' => 'required|string|max:255',
            'accept_terms' => 'required|in:1,true,on,yes',
            'player_name' => 'nullable|string|max:255', // Pour compatibilité
            'player_id' => 'nullable|string|max:255',
            'player_rank' => 'nullable|string|max:255',
            'contact_phone' => 'nullable|string|max:20',
            'additional_info' => 'nullable|string',
            'team_id' => 'nullable|exists:teams,id',
        ]);

        // Get user's wallet
        $wallet = $user->wallet;
        if (!$wallet || $wallet->balance < $championship->registration_fee) {
            return response()->json([
                'success' => false,
                'message' => 'Solde insuffisant. Solde actuel: $' . number_format($wallet->balance ?? 0, 2) . ' - Frais requis: $' . number_format($championship->registration_fee, 2),
            ], 400);
        }

        DB::beginTransaction();

        try {
            // Deduct registration fee from wallet
            $wallet->balance -= $championship->registration_fee;
            $wallet->save();

            // Create transaction record
            $transaction = Transaction::create([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'type' => 'championship_registration',
                'amount' => -$championship->registration_fee,
                'status' => 'confirmed',
                'provider' => 'system',
                'txid' => 'CHAMP_' . $id . '_' . $user->id . '_' . now()->format('YmdHis'),
                'description' => "Inscription au championnat: {$championship->name} - {$championship->game} Division {$championship->division}",
            ]);

            // Handle team logo upload
            $teamLogoPath = null;
            if ($request->hasFile('team_logo')) {
                $teamLogoPath = $request->file('team_logo')->store('championships/team-logos', 'public');
            }

            // Create registration
            $registration = ChampionshipRegistration::create([
                'championship_id' => $id,
                'user_id' => $user->id,
                'team_id' => $validated['team_id'] ?? null,
                'full_name' => $validated['full_name'],
                'team_name' => $validated['team_name'],
                'team_logo' => $teamLogoPath,
                'player_name' => $validated['player_name'] ?? $validated['full_name'],
                'player_username' => $validated['player_username'],
                'player_id' => $validated['player_id'] ?? null,
                'player_rank' => $validated['player_rank'] ?? null,
                'players_list' => $validated['players_list'],
                'contact_phone' => $validated['contact_phone'] ?? null,
                'contact_email' => $validated['contact_email'] ?? $user->email,
                'additional_info' => $validated['additional_info'] ?? null,
                'accept_terms' => in_array($validated['accept_terms'] ?? false, [true, '1', 'true', 'on', 'yes'], true),
                'status' => 'paid',
                'transaction_id' => $transaction->id,
                'fee_paid' => $championship->registration_fee,
                'registered_at' => now(),
                'paid_at' => now(),
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Inscription réussie! Votre paiement a été effectué.',
                'data' => $registration->load('championship'),
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de l\'inscription: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get user's registrations
     */
    public function myRegistrations(Request $request)
    {
        $user = auth('sanctum')->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Non authentifié',
            ], 401);
        }

        $registrations = ChampionshipRegistration::where('user_id', $user->id)
            ->with(['championship', 'transaction'])
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $registrations,
        ]);
    }

    /**
     * Get championship standings
     */
    public function standings($id)
    {
        $championship = Championship::findOrFail($id);

        $standings = ChampionshipRegistration::where('championship_id', $id)
            ->where('status', 'validated')
            ->orderBy('points', 'desc')
            ->orderBy('matches_won', 'desc')
            ->orderBy('matches_drawn', 'desc')
            ->with('user:id,username')
            ->get()
            ->map(function ($registration, $index) {
                $registration->current_position = $index + 1;
                return $registration;
            });

        return response()->json([
            'success' => true,
            'data' => $standings,
        ]);
    }

    /**
     * Get upcoming matches grouped by division
     * Only returns matches scheduled by admin (not auto-generated)
     */
    public function upcomingMatches(Request $request)
    {
        try {
            $limit = $request->get('limit', 10); // Number of matches per division
            
            // Get only matches that were created by admin (scheduled matches)
            $matches = ChampionshipMatch::whereHas('championship', function($query) {
                    $query->where('is_active', true)
                          ->whereIn('status', ['registration_open', 'validated', 'registration_closed', 'started']);
                })
                ->where('status', 'scheduled')
                ->where('scheduled_at', '>=', Carbon::now())
                ->where('scheduled_at', '<=', Carbon::now()->addDays(30))
                ->with([
                    'championship:id,name,game,division,start_date',
                    'player1:id,championship_id,team_name,team_logo,full_name,player_username,user_id',
                    'player1.user:id,username,email',
                    'player2:id,championship_id,team_name,team_logo,full_name,player_username,user_id',
                    'player2.user:id,username,email'
                ])
                ->orderBy('scheduled_at', 'asc')
                ->get();

            // Group matches by division
            $matchesByDivision = [
                '1' => [],
                '2' => [],
                '3' => [],
            ];

            foreach ($matches as $match) {
                if ($match->championship) {
                    $division = $match->championship->division;
                    if (isset($matchesByDivision[$division]) && count($matchesByDivision[$division]) < $limit) {
                        $matchesByDivision[$division][] = $match;
                    }
                }
            }

            return response()->json([
                'success' => true,
                'data' => $matchesByDivision,
            ]);
        } catch (\Exception $e) {
            \Log::error('Error loading upcoming matches: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString()
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Error loading scheduled matches: ' . $e->getMessage(),
                'data' => [
                    '1' => [],
                    '2' => [],
                    '3' => [],
                ],
            ], 500);
        }
    }
}

