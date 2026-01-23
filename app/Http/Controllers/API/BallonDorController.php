<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Season;
use App\Models\BallonDorNomination;
use App\Models\BallonDorVote;
use App\Models\BallonDorVotingRule;
use App\Models\User;
use App\Models\Clan;
use App\Models\Federation;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class BallonDorController extends Controller
{
    /**
     * Get current season
     */
    public function getCurrentSeason(Request $request)
    {
        $season = Season::current();
        
        if (!$season) {
            return response()->json([
                'success' => false,
                'message' => 'No current season found'
            ], 404);
        }

        $season->load(['votingRules', 'nominations' => function($query) {
            $query->orderBy('vote_count', 'desc');
        }]);

        return response()->json([
            'success' => true,
            'data' => $season
        ]);
    }

    /**
     * Get all seasons
     */
    public function getSeasons(Request $request)
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
     * Get nominations for a season
     */
    public function getNominations(Request $request, $seasonId = null)
    {
        $season = $seasonId ? Season::find($seasonId) : Season::current();

        if (!$season) {
            return response()->json([
                'success' => false,
                'message' => 'Season not found'
            ], 404);
        }

        $category = $request->get('category'); // player, clan, team

        $query = BallonDorNomination::where('season_id', $season->id)
            ->select('id', 'season_id', 'category', 'nominee_id', 'nominee_type', 'description', 'vote_count', 'is_winner', 'created_at');

        if ($category) {
            $query->where('category', $category);
        }

        $nominations = $query->orderBy('vote_count', 'desc')->get();
        
        // Eager load nominees efficiently based on type
        $userIds = $nominations->where('nominee_type', 'App\Models\User')->pluck('nominee_id')->unique();
        $clanIds = $nominations->where('nominee_type', 'App\Models\Clan')->pluck('nominee_id')->unique();
        $teamIds = $nominations->where('nominee_type', 'App\Models\Team')->pluck('nominee_id')->unique();
        
        $users = User::whereIn('id', $userIds)->select('id', 'username')->get()->keyBy('id');
        $clans = \App\Models\Clan::whereIn('id', $clanIds)->select('id', 'name')->get()->keyBy('id');
        $teams = Team::whereIn('id', $teamIds)->select('id', 'name')->get()->keyBy('id');
        
        // Attach loaded models to nominations
        foreach ($nominations as $nomination) {
            if ($nomination->nominee_type === 'App\Models\User' && isset($users[$nomination->nominee_id])) {
                $nomination->setRelation('nominee', $users[$nomination->nominee_id]);
            } elseif ($nomination->nominee_type === 'App\Models\Clan' && isset($clans[$nomination->nominee_id])) {
                $nomination->setRelation('nominee', $clans[$nomination->nominee_id]);
            } elseif ($nomination->nominee_type === 'App\Models\Team' && isset($teams[$nomination->nominee_id])) {
                $nomination->setRelation('nominee', $teams[$nomination->nominee_id]);
            }
        }

        // Group by category
        $grouped = [
            'player' => [],
            'clan' => [],
            'team' => [],
        ];

        foreach ($nominations as $nomination) {
            $grouped[$nomination->category][] = [
                'id' => $nomination->id,
                'nominee_id' => $nomination->nominee_id,
                'nominee_type' => $nomination->nominee_type,
                'nominee_name' => $nomination->nominee_name,
                'description' => $nomination->description,
                'achievements' => $nomination->achievements,
                'vote_count' => $nomination->vote_count,
                'rank' => $nomination->rank,
                'is_winner' => $nomination->is_winner,
                'nominee' => $nomination->nominee,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $grouped,
            'season' => $season
        ]);
    }

    /**
     * Submit a vote
     */
    public function vote(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required'
            ], 401);
        }

        $validator = Validator::make($request->all(), [
            'season_id' => 'required|exists:seasons,id',
            'nomination_id' => 'required|exists:ballon_dor_nominations,id',
            'category' => 'required|in:player,clan,team',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $season = Season::find($request->season_id);
        if (!$season) {
            return response()->json([
                'success' => false,
                'message' => 'Season not found'
            ], 404);
        }

        // Check if voting is open
        if (!$season->isVotingOpen()) {
            return response()->json([
                'success' => false,
                'message' => 'Voting is not currently open for this season'
            ], 400);
        }

        $nomination = BallonDorNomination::find($request->nomination_id);
        if (!$nomination || $nomination->season_id != $season->id) {
            return response()->json([
                'success' => false,
                'message' => 'Nomination not found'
            ], 404);
        }

        // Check voting rules
        $votingRule = $season->getVotingRule($request->category);
        if (!$votingRule) {
            return response()->json([
                'success' => false,
                'message' => 'Voting rules not configured for this category'
            ], 400);
        }

        // Check if user can vote
        if (!$votingRule->canUserVote($user)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not eligible to vote in this category'
            ], 403);
        }

        // Check if user already voted in this category
        $existingVote = BallonDorVote::where('season_id', $season->id)
            ->where('voter_id', $user->id)
            ->where('voter_type', 'App\Models\User')
            ->where('category', $request->category)
            ->first();

        if ($existingVote) {
            // Update existing vote
            $oldNominationId = $existingVote->nomination_id;
            $existingVote->nomination_id = $nomination->id;
            $existingVote->save();

            // Update vote counts
            if ($oldNominationId != $nomination->id) {
                BallonDorNomination::find($oldNominationId)?->decrementVoteCount();
                $nomination->incrementVoteCount();
            }
        } else {
            // Create new vote
            BallonDorVote::create([
                'season_id' => $season->id,
                'nomination_id' => $nomination->id,
                'voter_id' => $user->id,
                'voter_type' => 'App\Models\User',
                'category' => $request->category,
                'points' => 1,
            ]);

            $nomination->incrementVoteCount();
        }

        return response()->json([
            'success' => true,
            'message' => 'Vote submitted successfully'
        ]);
    }

    /**
     * Get user's votes for a season
     */
    public function getUserVotes(Request $request, $seasonId = null)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required'
            ], 401);
        }

        $season = $seasonId ? Season::find($seasonId) : Season::current();

        if (!$season) {
            return response()->json([
                'success' => false,
                'message' => 'Season not found'
            ], 404);
        }

        $votes = BallonDorVote::where('season_id', $season->id)
            ->where('voter_id', $user->id)
            ->where('voter_type', 'App\Models\User')
            ->with(['nomination.nominee'])
            ->get();

        $grouped = [
            'player' => null,
            'clan' => null,
            'team' => null,
        ];

        foreach ($votes as $vote) {
            $grouped[$vote->category] = [
                'nomination_id' => $vote->nomination_id,
                'nomination' => $vote->nomination,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $grouped
        ]);
    }

    /**
     * Get results for a season
     */
    public function getResults(Request $request, $seasonId = null)
    {
        $season = $seasonId ? Season::find($seasonId) : Season::current();

        if (!$season) {
            return response()->json([
                'success' => false,
                'message' => 'Season not found'
            ], 404);
        }

        // Get winners for each category
        $winners = BallonDorNomination::where('season_id', $season->id)
            ->where('is_winner', true)
            ->with(['nominee'])
            ->get();

        // Get top 3 for each category
        $topPlayers = BallonDorNomination::where('season_id', $season->id)
            ->where('category', 'player')
            ->orderBy('vote_count', 'desc')
            ->limit(3)
            ->with(['nominee'])
            ->get();

        $topClans = BallonDorNomination::where('season_id', $season->id)
            ->where('category', 'clan')
            ->orderBy('vote_count', 'desc')
            ->limit(3)
            ->with(['nominee'])
            ->get();

        $topTeams = BallonDorNomination::where('season_id', $season->id)
            ->where('category', 'team')
            ->orderBy('vote_count', 'desc')
            ->limit(3)
            ->with(['nominee'])
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'season' => $season,
                'winners' => $winners,
                'top_players' => $topPlayers,
                'top_clans' => $topClans,
                'top_teams' => $topTeams,
            ]
        ]);
    }

    /**
     * Check if user can vote
     */
    public function canVote(Request $request, $category)
    {
        $user = $request->user();
        $season = Season::current();

        if (!$season) {
            return response()->json([
                'success' => false,
                'can_vote' => false,
                'message' => 'No current season'
            ]);
        }

        if (!$season->isVotingOpen()) {
            return response()->json([
                'success' => true,
                'can_vote' => false,
                'message' => 'Voting is not open'
            ]);
        }

        $votingRule = $season->getVotingRule($category);
        if (!$votingRule) {
            return response()->json([
                'success' => true,
                'can_vote' => false,
                'message' => 'Voting rules not configured'
            ]);
        }

        $canVote = $user ? $votingRule->canUserVote($user) : $votingRule->community_can_vote;

        // Check if already voted
        $hasVoted = false;
        if ($user) {
            $hasVoted = BallonDorVote::where('season_id', $season->id)
                ->where('voter_id', $user->id)
                ->where('voter_type', 'App\Models\User')
                ->where('category', $category)
                ->exists();
        }

        return response()->json([
            'success' => true,
            'can_vote' => $canVote && !$hasVoted,
            'has_voted' => $hasVoted,
            'voting_open' => $season->isVotingOpen(),
        ]);
    }
}

