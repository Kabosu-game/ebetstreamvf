<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Clan;
use App\Models\ClanLeaderCandidate;
use App\Models\ClanVote;
use App\Models\ClanMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class ClanController extends Controller
{
    /**
     * Get all active clans
     */
    public function index(Request $request)
    {
        $clans = Clan::active()
            ->with(['leader:id,username', 'members:id,username'])
            ->withCount('members')
            ->latest()
            ->paginate(12);

        return response()->json([
            'success' => true,
            'data' => $clans
        ]);
    }

    /**
     * Get a specific clan with details
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        $clan = Clan::with([
            'leader:id,username',
            'members:id,username',
            'candidates' => function($query) {
                $query->with(['user:id,username'])->pending()->orderBy('vote_count', 'desc');
            },
        ])
        ->withCount(['members', 'candidates'])
        ->findOrFail($id);

        // Add helper attributes
        $clanData = $clan->toArray();
        $clanData['can_join'] = $clan->canJoin();
        
        if ($user) {
            $clanData['is_member'] = $clan->isMember($user->id);
            $clanData['is_leader'] = $clan->isLeader($user->id);
        }

        return response()->json([
            'success' => true,
            'data' => $clanData
        ]);
    }

    /**
     * Create a new clan
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:100|unique:clans,name',
            'description' => 'nullable|string|max:1000',
            'logo' => 'nullable|url|max:255',
            'max_members' => 'nullable|integer|min:5|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $clan = Clan::create([
                'name' => $request->name,
                'description' => $request->description,
                'logo' => $request->logo,
                'leader_id' => $user->id,
                'status' => 'active',
                'member_count' => 1,
                'max_members' => $request->max_members ?? 50,
            ]);

            // Add creator as member
            $clan->members()->attach($user->id);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Clan created successfully',
                'data' => $clan->load(['leader:id,username', 'members:id,username'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error creating clan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Join a clan
     */
    public function join(Request $request, $id)
    {
        $user = $request->user();
        $clan = Clan::active()->findOrFail($id);

        // Check if already a member
        if ($clan->isMember($user->id)) {
            return response()->json([
                'success' => false,
                'message' => 'You are already a member of this clan'
            ], 400);
        }

        // Check if clan is full
        if (!$clan->canJoin()) {
            return response()->json([
                'success' => false,
                'message' => 'This clan is full'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $clan->members()->attach($user->id);
            $clan->increment('member_count');

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Successfully joined the clan'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error joining clan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Leave a clan
     */
    public function leave(Request $request, $id)
    {
        $user = $request->user();
        $clan = Clan::findOrFail($id);

        if (!$clan->isMember($user->id)) {
            return response()->json([
                'success' => false,
                'message' => 'You are not a member of this clan'
            ], 400);
        }

        // Cannot leave if you are the leader
        if ($clan->isLeader($user->id)) {
            return response()->json([
                'success' => false,
                'message' => 'The leader cannot leave the clan. Please transfer leadership first.'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $clan->members()->detach($user->id);
            $clan->decrement('member_count');

            // Remove any pending candidacy
            ClanLeaderCandidate::where('clan_id', $clan->id)
                ->where('user_id', $user->id)
                ->where('status', 'pending')
                ->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Successfully left the clan'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error leaving clan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Apply to become clan leader
     */
    public function applyForLeadership(Request $request, $id)
    {
        $user = $request->user();
        $clan = Clan::findOrFail($id);

        // Must be a member
        if (!$clan->isMember($user->id)) {
            return response()->json([
                'success' => false,
                'message' => 'You must be a member of the clan to apply for leadership'
            ], 403);
        }

        // Cannot apply if already leader
        if ($clan->isLeader($user->id)) {
            return response()->json([
                'success' => false,
                'message' => 'You are already the leader of this clan'
            ], 400);
        }

        // Check if already applied
        $existingCandidate = ClanLeaderCandidate::where('clan_id', $clan->id)
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->first();

        if ($existingCandidate) {
            return response()->json([
                'success' => false,
                'message' => 'You have already applied for leadership'
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'motivation' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $candidate = ClanLeaderCandidate::create([
            'clan_id' => $clan->id,
            'user_id' => $user->id,
            'motivation' => $request->motivation,
            'vote_count' => 0,
            'status' => 'pending',
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Application submitted successfully',
            'data' => $candidate->load('user:id,username')
        ], 201);
    }

    /**
     * Vote for a leadership candidate
     */
    public function vote(Request $request, $id, $candidateId)
    {
        $user = $request->user();
        $clan = Clan::findOrFail($id);
        $candidate = ClanLeaderCandidate::where('clan_id', $clan->id)
            ->findOrFail($candidateId);

        // Must be a member
        if (!$clan->isMember($user->id)) {
            return response()->json([
                'success' => false,
                'message' => 'You must be a member of the clan to vote'
            ], 403);
        }

        // Cannot vote for yourself
        if ($candidate->user_id === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot vote for yourself'
            ], 400);
        }

        // Check if already voted
        $existingVote = ClanVote::where('clan_id', $clan->id)
            ->where('voter_id', $user->id)
            ->first();

        if ($existingVote) {
            // Update vote if voting for different candidate
            if ($existingVote->candidate_id !== $candidate->id) {
                // Decrement old candidate vote count
                ClanLeaderCandidate::where('id', $existingVote->candidate_id)
                    ->decrement('vote_count');

                // Update vote
                $existingVote->update(['candidate_id' => $candidate->id]);
                
                // Increment new candidate vote count
                $candidate->increment('vote_count');
            }

            return response()->json([
                'success' => true,
                'message' => 'Vote updated successfully'
            ]);
        }

        DB::beginTransaction();
        try {
            ClanVote::create([
                'clan_id' => $clan->id,
                'candidate_id' => $candidate->id,
                'voter_id' => $user->id,
            ]);

            $candidate->increment('vote_count');

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Vote submitted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error submitting vote: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get messages for a clan (members only)
     */
    public function getMessages(Request $request, $id)
    {
        $user = $request->user();
        $clan = Clan::findOrFail($id);

        // Must be a member
        if (!$clan->isMember($user->id)) {
            return response()->json([
                'success' => false,
                'message' => 'You must be a member of the clan to view messages'
            ], 403);
        }

        $messages = ClanMessage::where('clan_id', $clan->id)
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
     * Send a message to clan chat (members only)
     */
    public function sendMessage(Request $request, $id)
    {
        $user = $request->user();
        $clan = Clan::findOrFail($id);

        // Must be a member
        if (!$clan->isMember($user->id)) {
            return response()->json([
                'success' => false,
                'message' => 'You must be a member of the clan to send messages'
            ], 403);
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

        $message = ClanMessage::create([
            'clan_id' => $clan->id,
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
     * Admin: Get all clans with statistics
     */
    public function adminIndex(Request $request)
    {
        $query = Clan::with(['leader:id,username'])
            ->withCount(['members', 'candidates'])
            ->orderBy('created_at', 'desc');

        // Filters
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', '%' . $search . '%')
                  ->orWhere('description', 'like', '%' . $search . '%')
                  ->orWhereHas('leader', function($userQuery) use ($search) {
                      $userQuery->where('username', 'like', '%' . $search . '%');
                  });
            });
        }

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        $clans = $query->paginate($request->get('per_page', 50));

        // Statistics
        $stats = [
            'total_clans' => Clan::count(),
            'active_clans' => Clan::where('status', 'active')->count(),
            'total_members' => DB::table('clan_user')->count(),
            'pending_candidates' => ClanLeaderCandidate::where('status', 'pending')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $clans,
            'stats' => $stats
        ]);
    }

    /**
     * Admin: Get clan details
     */
    public function adminShow(Request $request, $id)
    {
        $clan = Clan::with([
            'leader:id,username,email',
            'members:id,username,email',
            'candidates' => function($query) {
                $query->with(['user:id,username'])->orderBy('vote_count', 'desc');
            },
            'allMessages' => function($query) {
                $query->with(['user:id,username'])->latest()->limit(100);
            }
        ])
        ->withCount(['members', 'candidates', 'allMessages'])
        ->findOrFail($id);

        // Statistics
        $stats = [
            'total_members' => $clan->members_count,
            'pending_candidates' => $clan->candidates()->where('status', 'pending')->count(),
            'total_messages' => $clan->allMessages()->count(),
            'total_votes' => ClanVote::where('clan_id', $clan->id)->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $clan,
            'stats' => $stats
        ]);
    }

    /**
     * Admin: Update clan
     */
    public function adminUpdate(Request $request, $id)
    {
        $clan = Clan::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|required|string|max:100|unique:clans,name,' . $id,
            'description' => 'nullable|string|max:1000',
            'logo' => 'nullable|url|max:255',
            'status' => 'sometimes|required|in:active,inactive',
            'leader_id' => 'nullable|exists:users,id',
            'max_members' => 'nullable|integer|min:5|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $clan->update($request->only([
            'name', 'description', 'logo', 'status', 'leader_id', 'max_members'
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Clan updated successfully',
            'data' => $clan->load(['leader:id,username', 'members:id,username'])
        ]);
    }

    /**
     * Admin: Delete clan
     */
    public function adminDestroy(Request $request, $id)
    {
        $clan = Clan::findOrFail($id);

        DB::beginTransaction();
        try {
            // Delete related data
            ClanVote::where('clan_id', $clan->id)->delete();
            ClanLeaderCandidate::where('clan_id', $clan->id)->delete();
            ClanMessage::where('clan_id', $clan->id)->delete();
            $clan->members()->detach();
            
            // Delete clan
            $clan->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Clan deleted successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error deleting clan: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin: Remove member from clan
     */
    public function adminRemoveMember(Request $request, $id, $userId)
    {
        $clan = Clan::findOrFail($id);
        $user = \App\Models\User::findOrFail($userId);

        if (!$clan->isMember($userId)) {
            return response()->json([
                'success' => false,
                'message' => 'User is not a member of this clan'
            ], 400);
        }

        DB::beginTransaction();
        try {
            $clan->members()->detach($userId);
            $clan->decrement('member_count');

            // If user was leader, remove leadership
            if ($clan->leader_id === $userId) {
                $clan->update(['leader_id' => null]);
            }

            // Remove any pending candidacy
            ClanLeaderCandidate::where('clan_id', $clan->id)
                ->where('user_id', $userId)
                ->where('status', 'pending')
                ->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Member removed successfully'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error removing member: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin: Approve a leadership candidate (the one with most votes)
     */
    public function approveLeader(Request $request, $id)
    {
        // Check if user is admin
        $user = $request->user();
        if (!$user || $user->role !== 'admin') {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized. Admin access required.'
            ], 403);
        }

        $clan = Clan::findOrFail($id);

        // Get candidate with most votes
        $topCandidate = ClanLeaderCandidate::where('clan_id', $clan->id)
            ->pending()
            ->orderBy('vote_count', 'desc')
            ->first();

        if (!$topCandidate) {
            return response()->json([
                'success' => false,
                'message' => 'No pending candidates found'
            ], 404);
        }

        DB::beginTransaction();
        try {
            // Update candidate status
            $topCandidate->update([
                'status' => 'approved',
                'approved_at' => now(),
            ]);

            // Reject other candidates
            ClanLeaderCandidate::where('clan_id', $clan->id)
                ->where('id', '!=', $topCandidate->id)
                ->where('status', 'pending')
                ->update(['status' => 'rejected']);

            // Update clan leader
            $clan->update([
                'leader_id' => $topCandidate->user_id
            ]);

            // Clear votes for this election
            ClanVote::where('clan_id', $clan->id)->delete();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'New leader approved successfully',
                'data' => $clan->load('leader:id,username')
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error approving leader: ' . $e->getMessage()
            ], 500);
        }
    }
}
