<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Federation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminFederationController extends Controller
{
    /**
     * List all federations
     */
    public function index(Request $request)
    {
        $query = Federation::with(['user', 'tournaments']);

        // Filter by status
        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        // Search
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('country', 'like', "%{$search}%");
            });
        }

        $perPage = $request->get('per_page', 20);
        $federations = $query->orderBy('created_at', 'desc')->paginate($perPage);

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
        $federation = Federation::with(['user', 'tournaments'])->find($id);

        if (!$federation) {
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
     * Approve a federation
     */
    public function approve(Request $request, $id)
    {
        $federation = Federation::find($id);

        if (!$federation) {
            return response()->json([
                'success' => false,
                'message' => 'Federation not found'
            ], 404);
        }

        if ($federation->status === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'Federation is already approved'
            ], 400);
        }

        $federation->status = 'approved';
        $federation->rejection_reason = null;
        $federation->save();

        return response()->json([
            'success' => true,
            'message' => 'Federation approved successfully',
            'data' => $federation
        ]);
    }

    /**
     * Reject a federation
     */
    public function reject(Request $request, $id)
    {
        $federation = Federation::find($id);

        if (!$federation) {
            return response()->json([
                'success' => false,
                'message' => 'Federation not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $federation->status = 'rejected';
        $federation->rejection_reason = $request->reason;
        $federation->save();

        return response()->json([
            'success' => true,
            'message' => 'Federation rejected',
            'data' => $federation
        ]);
    }

    /**
     * Suspend a federation
     */
    public function suspend(Request $request, $id)
    {
        $federation = Federation::find($id);

        if (!$federation) {
            return response()->json([
                'success' => false,
                'message' => 'Federation not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $federation->status = 'suspended';
        $federation->rejection_reason = $request->reason;
        $federation->save();

        return response()->json([
            'success' => true,
            'message' => 'Federation suspended',
            'data' => $federation
        ]);
    }

    /**
     * Update federation (admin override)
     */
    public function update(Request $request, $id)
    {
        $federation = Federation::find($id);

        if (!$federation) {
            return response()->json([
                'success' => false,
                'message' => 'Federation not found'
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'sometimes|string|max:255|unique:federations,name,' . $id,
            'description' => 'nullable|string|max:5000',
            'website' => 'nullable|url|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:50',
            'country' => 'nullable|string|max:100',
            'city' => 'nullable|string|max:100',
            'address' => 'nullable|string|max:500',
            'status' => 'sometimes|in:pending,approved,rejected,suspended',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $federation->update($request->only([
            'name', 'description', 'website', 'email', 'phone', 'country', 'city', 'address', 'status'
        ]));

        $federation->load('user');

        return response()->json([
            'success' => true,
            'message' => 'Federation updated successfully',
            'data' => $federation
        ]);
    }

    /**
     * Delete a federation
     */
    public function destroy($id)
    {
        $federation = Federation::find($id);

        if (!$federation) {
            return response()->json([
                'success' => false,
                'message' => 'Federation not found'
            ], 404);
        }

        $federation->delete();

        return response()->json([
            'success' => true,
            'message' => 'Federation deleted successfully'
        ]);
    }
}

