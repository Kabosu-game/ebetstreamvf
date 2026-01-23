<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Season;
use App\Models\BallonDorNomination;
use App\Models\BallonDorVotingRule;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class AdminBallonDorController extends Controller
{
    /**
     * List all seasons
     */
    public function seasons(Request $request)
    {
        $query = Season::query();

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $seasons = $query->orderBy('start_date', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $seasons
        ]);
    }

    /**
     * Create a new season
     */
    public function createSeason(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:seasons,name',
            'description' => 'nullable|string|max:5000',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after:start_date',
            'voting_start_date' => 'nullable|date|after:end_date',
            'voting_end_date' => 'nullable|date|after:voting_start_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // Set previous current season to false
        Season::where('is_current', true)->update(['is_current' => false]);

        $season = Season::create([
            'name' => $request->name,
            'slug' => \Illuminate\Support\Str::slug($request->name),
            'description' => $request->description,
            'start_date' => $request->start_date,
            'end_date' => $request->end_date,
            'voting_start_date' => $request->voting_start_date,
            'voting_end_date' => $request->voting_end_date,
            'status' => 'upcoming',
            'is_current' => true,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Season created successfully',
            'data' => $season
        ], 201);
    }

    /**
     * Update a season
     */
    public function updateSeason(Request $request, $id)
    {
        $season = Season::find($id);

        if (!$season) {
            return response()->json([
                'success' => false,
                'message' => 'Season not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255|unique:seasons,name,' . $id,
            'description' => 'nullable|string|max:5000',
            'start_date' => 'sometimes|date',
            'end_date' => 'sometimes|date|after:start_date',
            'voting_start_date' => 'nullable|date',
            'voting_end_date' => 'nullable|date|after:voting_start_date',
            'status' => 'sometimes|in:upcoming,active,voting,completed',
            'is_current' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        // If setting this season as current, unset others
        if ($request->has('is_current') && $request->is_current) {
            Season::where('id', '!=', $id)->update(['is_current' => false]);
        }

        $season->update($request->only([
            'name', 'description', 'start_date', 'end_date', 
            'voting_start_date', 'voting_end_date', 'status', 'is_current'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Season updated successfully',
            'data' => $season
        ]);
    }

    /**
     * Get nominations for a season
     */
    public function nominations(Request $request, $seasonId)
    {
        $season = Season::find($seasonId);

        if (!$season) {
            return response()->json([
                'success' => false,
                'message' => 'Season not found'
            ], 404);
        }

        $query = BallonDorNomination::where('season_id', $seasonId)
            ->with(['nominee']);

        if ($request->has('category')) {
            $query->where('category', $request->category);
        }

        $nominations = $query->orderBy('vote_count', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $nominations
        ]);
    }

    /**
     * Create a nomination
     */
    public function createNomination(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'season_id' => 'required|exists:seasons,id',
            'category' => 'required|in:player,clan,team',
            'category_label' => 'required|string|max:255',
            'nominee_id' => 'required|integer',
            'nominee_type' => 'required|string|in:App\Models\User,App\Models\Clan',
            'description' => 'nullable|string|max:5000',
            'achievements' => 'nullable|string|max:5000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $nomination = BallonDorNomination::create($request->only([
            'season_id', 'category', 'category_label', 'nominee_id', 
            'nominee_type', 'description', 'achievements'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Nomination created successfully',
            'data' => $nomination
        ], 201);
    }

    /**
     * Update a nomination
     */
    public function updateNomination(Request $request, $id)
    {
        $nomination = BallonDorNomination::find($id);

        if (!$nomination) {
            return response()->json([
                'success' => false,
                'message' => 'Nomination not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'description' => 'nullable|string|max:5000',
            'achievements' => 'nullable|string|max:5000',
            'is_winner' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $nomination->update($request->only(['description', 'achievements', 'is_winner']));

        return response()->json([
            'success' => true,
            'message' => 'Nomination updated successfully',
            'data' => $nomination
        ]);
    }

    /**
     * Set winners for a season
     */
    public function setWinners(Request $request, $seasonId)
    {
        $season = Season::find($seasonId);

        if (!$season) {
            return response()->json([
                'success' => false,
                'message' => 'Season not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'winners' => 'required|array',
            'winners.player' => 'nullable|exists:ballon_dor_nominations,id',
            'winners.clan' => 'nullable|exists:ballon_dor_nominations,id',
            'winners.team' => 'nullable|exists:ballon_dor_nominations,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            // Reset all winners for this season
            BallonDorNomination::where('season_id', $seasonId)
                ->update(['is_winner' => false]);

            // Set new winners
            $winners = $request->winners;
            foreach ($winners as $category => $nominationId) {
                if ($nominationId) {
                    $nomination = BallonDorNomination::where('id', $nominationId)
                        ->where('season_id', $seasonId)
                        ->where('category', $category)
                        ->first();

                    if ($nomination) {
                        $nomination->is_winner = true;
                        $nomination->save();
                    }
                }
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Winners set successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error setting winners: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get voting rules for a season
     */
    public function votingRules(Request $request, $seasonId)
    {
        $season = Season::find($seasonId);

        if (!$season) {
            return response()->json([
                'success' => false,
                'message' => 'Season not found'
            ], 404);
        }

        $rules = BallonDorVotingRule::where('season_id', $seasonId)->get();

        return response()->json([
            'success' => true,
            'data' => $rules
        ]);
    }

    /**
     * Update voting rules
     */
    public function updateVotingRules(Request $request, $seasonId)
    {
        $season = Season::find($seasonId);

        if (!$season) {
            return response()->json([
                'success' => false,
                'message' => 'Season not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'rules' => 'required|array',
            'rules.*.category' => 'required|in:player,clan,team',
            'rules.*.community_can_vote' => 'sometimes|boolean',
            'rules.*.players_can_vote' => 'sometimes|boolean',
            'rules.*.federations_can_vote' => 'sometimes|boolean',
            'rules.*.min_participations' => 'nullable|integer',
            'rules.*.max_votes_per_category' => 'sometimes|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            foreach ($request->rules as $ruleData) {
                $rule = BallonDorVotingRule::updateOrCreate(
                    [
                        'season_id' => $seasonId,
                        'category' => $ruleData['category'],
                    ],
                    $ruleData
                );
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Voting rules updated successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error updating rules: ' . $e->getMessage()
            ], 500);
        }
    }
}

