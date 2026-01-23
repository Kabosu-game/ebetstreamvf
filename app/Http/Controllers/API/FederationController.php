<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Federation;
use App\Models\Tournament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class FederationController extends Controller
{
    /**
     * Get all federations (public)
     */
    public function index(Request $request)
    {
        $query = Federation::with(['user:id,username'])
            ->select('id', 'name', 'slug', 'logo', 'country', 'status', 'user_id', 'created_at');

        // Filter by status (only show approved for public)
        if ($request->has('status')) {
            $query->where('status', $request->status);
        } else {
            // By default, only show approved federations for non-authenticated users
            if (!$request->user()) {
                $query->where('status', 'approved');
            }
        }

        // Filter by country
        if ($request->has('country')) {
            $query->where('country', $request->country);
        }

        // Search by name
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $perPage = min($request->get('per_page', 15), 50); // Max 50 per page
        $federations = $query->orderBy('name')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $federations
        ]);
    }

    /**
     * Get a specific federation
     */
    public function show($id)
    {
        $federation = Federation::with(['user', 'tournaments' => function($query) {
            $query->orderBy('start_at', 'desc');
        }])->find($id);

        if (!$federation) {
            return response()->json([
                'success' => false,
                'message' => 'Federation not found'
            ], 404);
        }

        // Only show approved federations to public
        if (!$federation->isApproved() && !auth()->check()) {
            return response()->json([
                'success' => false,
                'message' => 'Federation not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $federation
        ]);
    }

    /**
     * Create a new federation
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required'
            ], 401);
        }

        // Check if user already has a federation
        $existingFederation = Federation::where('user_id', $user->id)->first();
        if ($existingFederation) {
            return response()->json([
                'success' => false,
                'message' => 'You already have a federation registered'
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255|unique:federations,name',
            'description' => 'nullable|string|max:5000',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'website' => 'nullable|url|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'country' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->only([
            'name', 'description', 'website', 'email', 'phone', 'country', 'city', 'address'
        ]);

        // Generate unique slug
        $slug = Str::slug($request->name);
        $originalSlug = $slug;
        $counter = 1;
        while (Federation::where('slug', $slug)->exists()) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }
        $data['slug'] = $slug;
        $data['user_id'] = $user->id;
        $data['status'] = 'pending'; // Needs admin approval

        // Handle logo upload
        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('federations/logos', 'public');
            $data['logo'] = $path;
        }

        $federation = Federation::create($data);
        $federation->load('user');

        return response()->json([
            'success' => true,
            'message' => 'Federation registration submitted successfully. Waiting for admin approval.',
            'data' => $federation
        ], 201);
    }

    /**
     * Update federation (only by owner)
     */
    public function update(Request $request, $id)
    {
        $user = $request->user();
        $federation = Federation::find($id);

        if (!$federation) {
            return response()->json([
                'success' => false,
                'message' => 'Federation not found'
            ], 404);
        }

        // Check if user owns this federation
        if ($federation->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255|unique:federations,name,' . $id,
            'description' => 'nullable|string|max:5000',
            'logo' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'website' => 'nullable|url|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'country' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->only([
            'name', 'description', 'website', 'email', 'phone', 'country', 'city', 'address'
        ]);

        // Update slug if name changed
        if ($request->has('name') && $request->name !== $federation->name) {
            $slug = Str::slug($request->name);
            $originalSlug = $slug;
            $counter = 1;
            while (Federation::where('slug', $slug)->where('id', '!=', $id)->exists()) {
                $slug = $originalSlug . '-' . $counter;
                $counter++;
            }
            $data['slug'] = $slug;
        }

        // Handle logo upload
        if ($request->hasFile('logo')) {
            // Delete old logo
            if ($federation->logo && Storage::disk('public')->exists($federation->logo)) {
                Storage::disk('public')->delete($federation->logo);
            }
            $path = $request->file('logo')->store('federations/logos', 'public');
            $data['logo'] = $path;
        }

        $federation->update($data);
        $federation->load('user');

        return response()->json([
            'success' => true,
            'message' => 'Federation updated successfully',
            'data' => $federation
        ]);
    }

    /**
     * Get tournaments for a federation
     */
    public function tournaments(Request $request, $id)
    {
        $federation = Federation::select('id', 'status')->find($id);

        if (!$federation) {
            return response()->json([
                'success' => false,
                'message' => 'Federation not found'
            ], 404);
        }

        // Only show approved federations to public
        if (!$federation->isApproved() && !auth()->check()) {
            return response()->json([
                'success' => false,
                'message' => 'Federation not found'
            ], 404);
        }

        $query = Tournament::where('federation_id', $federation->id)
            ->select('id', 'federation_id', 'creator_id', 'title', 'name', 'game', 'entry_fee', 'reward', 'type', 'division', 'status', 'start_at', 'end_at', 'max_participants', 'created_at')
            ->with(['creator:id,username']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Filter by type
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Filter by division
        if ($request->has('division')) {
            $query->where('division', $request->division);
        }

        // Group by division if requested
        if ($request->has('group_by_division') && $request->group_by_division) {
            $tournaments = $query->orderBy('division', 'asc')->orderBy('start_at', 'desc')->get();
            
            // Group by division
            $grouped = [
                '1' => [],
                '2' => [],
                '3' => [],
            ];
            
            foreach ($tournaments as $tournament) {
                $division = $tournament->division ?? '3';
                $grouped[$division][] = $tournament;
            }
            
            return response()->json([
                'success' => true,
                'data' => $grouped,
                'grouped_by_division' => true
            ]);
        }

        $perPage = min($request->get('per_page', 15), 50); // Max 50 per page
        $tournaments = $query->orderBy('division', 'asc')->orderBy('start_at', 'desc')->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $tournaments
        ]);
    }

    /**
     * Get user's federation (if exists)
     */
    public function myFederation(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Authentication required'
            ], 401);
        }

        $federation = Federation::where('user_id', $user->id)
            ->with(['tournaments'])
            ->first();

        if (!$federation) {
            return response()->json([
                'success' => false,
                'message' => 'No federation found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $federation
        ]);
    }
}

