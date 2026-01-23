<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\Clan;
use App\Models\TeamMarketplaceListing;
use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;

class TeamMarketplaceController extends Controller
{
    /**
     * List all available team listings
     */
    public function index(Request $request)
    {
        $query = TeamMarketplaceListing::with([
                'team:id,name,logo,owner_id,status',
                'team.owner:id,username',
                'seller:id,username'
            ])
            ->select('id', 'team_id', 'seller_id', 'listing_type', 'price', 'loan_fee', 'loan_duration_days', 'status', 'created_at')
            ->active();

        // Filter by type
        if ($request->has('type')) {
            $query->where('listing_type', $request->type);
        }

        // Filter by search
        if ($request->has('search')) {
            $search = $request->search;
            $query->whereHas('team', function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
            });
        }

        $perPage = min($request->get('per_page', 12), 50); // Max 50 per page
        $listings = $query->orderBy('created_at', 'desc')
            ->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $listings
        ]);
    }

    /**
     * Get a specific listing
     */
    public function show($id)
    {
        $listing = TeamMarketplaceListing::with(['team.owner', 'seller', 'buyer'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $listing
        ]);
    }

    /**
     * Create a new listing (sale or loan)
     */
    public function store(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'team_id' => 'required|exists:teams,id',
            'listing_type' => 'required|in:sale,loan',
            'price' => 'required_if:listing_type,sale|nullable|numeric|min:0',
            'loan_fee' => 'required_if:listing_type,loan|nullable|numeric|min:0',
            'loan_duration_days' => 'required_if:listing_type,loan|nullable|integer|min:1|max:365',
            'conditions' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        DB::beginTransaction();
        try {
            $team = Team::findOrFail($request->team_id);

            // Verify ownership
            if (!$team->isOwner($user->id)) {
                return response()->json([
                    'success' => false,
                    'message' => 'You are not the owner of this team'
                ], 403);
            }

            // Check if team is available
            if ($team->status !== 'active') {
                return response()->json([
                    'success' => false,
                    'message' => 'This team is not available for listing'
                ], 400);
            }

            // Check if there's already an active listing
            if ($team->activeListing()->exists()) {
                return response()->json([
                    'success' => false,
                    'message' => 'This team already has an active listing'
                ], 400);
            }

            $listing = TeamMarketplaceListing::create([
                'team_id' => $team->id,
                'seller_id' => $user->id,
                'listing_type' => $request->listing_type,
                'price' => $request->price,
                'loan_fee' => $request->loan_fee,
                'loan_duration_days' => $request->loan_duration_days,
                'conditions' => $request->conditions,
                'status' => 'active',
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Listing created successfully',
                'data' => $listing->load(['team.owner', 'seller'])
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error creating listing: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Cancel a listing
     */
    public function cancel(Request $request, $id)
    {
        $user = $request->user();

        $listing = TeamMarketplaceListing::findOrFail($id);

        // Verify ownership
        if ($listing->seller_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You are not the seller of this listing'
            ], 403);
        }

        if ($listing->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'This listing cannot be cancelled'
            ], 400);
        }

        $listing->status = 'cancelled';
        $listing->save();

        return response()->json([
            'success' => true,
            'message' => 'Listing cancelled successfully'
        ]);
    }

    /**
     * Buy a team (for sale listings)
     */
    public function buy(Request $request, $id)
    {
        $user = $request->user();

        $listing = TeamMarketplaceListing::with(['team', 'seller'])
            ->forSale()
            ->active()
            ->findOrFail($id);

        // Cannot buy own listing
        if ($listing->seller_id === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot buy your own listing'
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Check buyer wallet
            $buyerWallet = Wallet::where('user_id', $user->id)->first();
            if (!$buyerWallet || $buyerWallet->balance < $listing->price) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient balance. You need ' . $listing->price . ' EBT'
                ], 400);
            }

            // Deduct from buyer
            $buyerWallet->balance -= $listing->price;
            $buyerWallet->save();

            // Add to seller
            $sellerWallet = Wallet::where('user_id', $listing->seller_id)->first();
            if ($sellerWallet) {
                $sellerWallet->balance += $listing->price;
                $sellerWallet->save();
            }

            // Update listing
            $listing->status = 'sold';
            $listing->buyer_id = $user->id;
            $listing->sold_at = now();
            $listing->save();

            // Transfer team ownership
            $listing->team->owner_id = $user->id;
            $listing->team->status = 'active';
            $listing->team->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Team purchased successfully',
                'data' => $listing->load(['team.owner', 'buyer'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error purchasing team: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Loan a team (for loan listings)
     */
    public function loan(Request $request, $id)
    {
        $user = $request->user();

        $listing = TeamMarketplaceListing::with(['team', 'seller'])
            ->forLoan()
            ->active()
            ->findOrFail($id);

        // Cannot loan own listing
        if ($listing->seller_id === $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'You cannot loan your own listing'
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Check borrower wallet
            $borrowerWallet = Wallet::where('user_id', $user->id)->first();
            if (!$borrowerWallet || $borrowerWallet->balance < $listing->loan_fee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Insufficient balance. You need ' . $listing->loan_fee . ' EBT'
                ], 400);
            }

            // Deduct from borrower
            $borrowerWallet->balance -= $listing->loan_fee;
            $borrowerWallet->save();

            // Add to lender
            $lenderWallet = Wallet::where('user_id', $listing->seller_id)->first();
            if ($lenderWallet) {
                $lenderWallet->balance += $listing->loan_fee;
                $lenderWallet->save();
            }

            // Update listing
            $listing->status = 'loaned';
            $listing->buyer_id = $user->id;
            $listing->loan_start_date = now();
            $listing->loan_end_date = now()->addDays($listing->loan_duration_days);
            $listing->save();

            // Update team status
            $listing->team->status = 'loaned';
            $listing->team->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Team loaned successfully',
                'data' => $listing->load(['team.owner', 'buyer'])
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Error loaning team: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user's listings
     */
    public function myListings(Request $request)
    {
        $user = $request->user();

        $listings = TeamMarketplaceListing::with(['team', 'buyer'])
            ->where('seller_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 12));

        return response()->json([
            'success' => true,
            'data' => $listings
        ]);
    }

    /**
     * Get user's teams and clans available for marketplace
     * Returns teams where user is owner and clans where user is leader
     */
    public function myTeams(Request $request)
    {
        $user = $request->user();

        // Get teams where user is owner and status is active
        $teams = Team::where('owner_id', $user->id)
            ->where('status', 'active')
            ->select('id', 'name', 'logo', 'owner_id', 'status')
            ->get()
            ->map(function ($team) {
                return [
                    'id' => $team->id,
                    'name' => $team->name,
                    'logo' => $team->logo,
                    'type' => 'team',
                ];
            });

        // Get clans where user is leader and status is active
        $clans = Clan::where('leader_id', $user->id)
            ->where('status', 'active')
            ->select('id', 'name', 'logo', 'leader_id', 'status')
            ->get()
            ->map(function ($clan) {
                return [
                    'id' => $clan->id,
                    'name' => $clan->name,
                    'logo' => $clan->logo,
                    'type' => 'clan',
                ];
            });

        // Merge teams and clans
        $availableItems = $teams->concat($clans);

        // Filter out items that already have active listings
        $activeListingTeamIds = TeamMarketplaceListing::where('status', 'active')
            ->pluck('team_id')
            ->toArray();

        $availableItems = $availableItems->filter(function ($item) use ($activeListingTeamIds) {
            return !in_array($item['id'], $activeListingTeamIds);
        });

        return response()->json([
            'success' => true,
            'data' => $availableItems->values()
        ]);
    }
}

