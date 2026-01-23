<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Tournament;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class TournamentController extends Controller
{
    /**
     * Register a team to a tournament (required for team tournaments).
     */
    public function registerTeam(Request $request, $id)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required'
            ], 401);
        }

        $tournament = Tournament::find($id);

        if (!$tournament) {
            return response()->json([
                'success' => false,
                'message' => 'Tournament not found'
            ], 404);
        }

        // Vérifier que le tournoi est de type team
        if (!$tournament->requiresTeam()) {
            return response()->json([
                'success' => false,
                'message' => 'This tournament does not require team registration'
            ], 400);
        }

        // Vérifier que le tournoi est encore ouvert aux inscriptions
        if ($tournament->status !== 'upcoming') {
            return response()->json([
                'success' => false,
                'message' => 'Registration is closed for this tournament'
            ], 400);
        }

        // Vérifier la limite de participants
        if ($tournament->max_participants) {
            $currentTeamsCount = $tournament->confirmedTeams()->count();
            if ($currentTeamsCount >= $tournament->max_participants) {
                return response()->json([
                    'success' => false,
                    'message' => 'The tournament has reached the maximum number of teams'
                ], 400);
            }
        }

        $validator = Validator::make($request->all(), [
            'team_id' => 'required|exists:teams,id'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $team = Team::find($request->team_id);

        // Vérifier que l'utilisateur est propriétaire de l'équipe
        if (!$team->isOwner($user->id)) {
            return response()->json([
                'success' => false,
                'message' => 'You must be the owner of the team to register it'
            ], 403);
        }

        // Vérifier que l'équipe est visible publiquement (status = active)
        if ($team->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'The team must be publicly visible (active status) to participate'
            ], 400);
        }

        // Vérifier que l'équipe n'est pas déjà inscrite
        $existingRegistration = DB::table('tournament_teams')
            ->where('tournament_id', $tournament->id)
            ->where('team_id', $team->id)
            ->first();

        if ($existingRegistration) {
            return response()->json([
                'success' => false,
                'message' => 'This team is already registered for this tournament'
            ], 400);
        }

        // Vérifier si l'utilisateur a déjà une équipe inscrite (une personne = une équipe max)
        $userTeamRegistered = DB::table('tournament_teams')
            ->where('tournament_id', $tournament->id)
            ->where('registered_by', $user->id)
            ->exists();

        if ($userTeamRegistered) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a team registered for this tournament'
            ], 400);
        }

        // Débiter les frais d'inscription si nécessaire
        if ($tournament->entry_fee > 0) {
            $wallet = $user->wallet;
            
            if (!$wallet || $wallet->balance < $tournament->entry_fee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient balance to pay the entry fee. Required: ' . $tournament->entry_fee . ' EBT'
                ], 400);
            }

            // Débiter le wallet
            $wallet->balance -= $tournament->entry_fee;
            $wallet->save();

            // Enregistrer la transaction
            DB::table('transactions')->insert([
                'user_id' => $user->id,
                'type' => 'debit',
                'amount' => $tournament->entry_fee,
                'provider' => 'tournament_entry_fee',
                'status' => 'confirmed',
                'description' => "Entry fee for tournament: {$tournament->title}",
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Inscrire l'équipe
        $tournament->teams()->attach($team->id, [
            'registered_by' => $user->id,
            'status' => 'confirmed',
            'registered_at' => now(),
        ]);

        $tournament->load(['teams' => function($query) {
            $query->select('teams.id', 'teams.name', 'teams.logo', 'teams.owner_id')
                ->with('owner:id,username');
        }]);

        return response()->json([
            'success' => true,
            'message' => 'Team registered successfully for the tournament',
            'data' => [
                'tournament' => $tournament,
                'team' => $team->load('owner'),
            ]
        ], 201);
    }

    /**
     * Get participating teams for a tournament.
     */
    public function getTeams(Request $request, $id)
    {
        $tournament = Tournament::select('id', 'title', 'type', 'status', 'start_at', 'max_participants')
            ->find($id);

        if (!$tournament) {
            return response()->json([
                'success' => false,
                'message' => 'Tournament not found'
            ], 404);
        }

        // Pour les tournois de type team, afficher les équipes
        if ($tournament->requiresTeam()) {
            $perPage = min($request->get('per_page', 20), 50);
            $teams = $tournament->confirmedTeams()
                ->select('teams.id', 'teams.name', 'teams.logo', 'teams.owner_id', 'teams.division')
                ->with(['owner:id,username'])
                ->paginate($perPage);

            return response()->json([
                'success' => true,
                'data' => [
                    'tournament' => $tournament,
                    'teams' => $teams,
                    'total_teams' => $teams->total(),
                ]
            ]);
        }

        // Pour les tournois individuels, afficher les participants
        $perPage = min($request->get('per_page', 20), 50);
        $participants = $tournament->participants()
            ->select('users.id', 'users.username')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => [
                'tournament' => $tournament,
                'participants' => $participants,
                'total_participants' => $participants->total(),
            ]
        ]);
    }

    /**
     * Get user's teams available for tournament registration.
     */
    public function getMyTeams(Request $request, $id)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required'
            ], 401);
        }

        $tournament = Tournament::find($id);

        if (!$tournament) {
            return response()->json([
                'success' => false,
                'message' => 'Tournament not found'
            ], 404);
        }

        // Récupérer les équipes actives de l'utilisateur
        $teams = Team::where('owner_id', $user->id)
            ->where('status', 'active')
            ->select('id', 'name', 'logo', 'owner_id', 'status', 'division')
            ->with(['members:id,username'])
            ->get();

        // Vérifier quelles équipes sont déjà inscrites
        $registeredTeamIds = DB::table('tournament_teams')
            ->where('tournament_id', $tournament->id)
            ->pluck('team_id')
            ->toArray();

        $teams = $teams->map(function($team) use ($registeredTeamIds) {
            $team->is_registered = in_array($team->id, $registeredTeamIds);
            return $team;
        });

        return response()->json([
            'success' => true,
            'data' => $teams
        ]);
    }
}
