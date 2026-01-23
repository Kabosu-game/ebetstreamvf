<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CertificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminCertificationRequestController extends Controller
{
    /**
     * Get all certification requests with filters
     */
    public function index(Request $request)
    {
        $query = CertificationRequest::with(['user:id,username,email']);

        // Filter by type
        if ($request->has('type') && $request->type) {
            $query->where('type', $request->type);
        }

        // Filter by status
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        $requests = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $requests
        ]);
    }

    /**
     * Get a specific certification request
     */
    public function show($id)
    {
        $request = CertificationRequest::with(['user:id,username,email', 'reviewer:id,username'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $request
        ]);
    }

    /**
     * Approve a certification request
     */
    public function approve(Request $request, $id)
    {
        $user = $request->user();
        $certificationRequest = CertificationRequest::findOrFail($id);

        if ($certificationRequest->status === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'This certification request is already approved.'
            ], 400);
        }

        if ($certificationRequest->status === 'rejected') {
            return response()->json([
                'success' => false,
                'message' => 'This certification request has been rejected and cannot be approved.'
            ], 400);
        }

        $certificationRequest->update([
            'status' => 'approved',
            'approved_at' => now(),
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        // TODO: Add logic to grant certification access to user
        // For example: update user role, add badge, etc.

        return response()->json([
            'success' => true,
            'message' => 'Certification request approved successfully.',
            'data' => $certificationRequest->load(['user', 'reviewer'])
        ]);
    }

    /**
     * Reject a certification request
     */
    public function reject(Request $request, $id)
    {
        $user = $request->user();
        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $certificationRequest = CertificationRequest::findOrFail($id);

        if ($certificationRequest->status === 'rejected') {
            return response()->json([
                'success' => false,
                'message' => 'This certification request is already rejected.'
            ], 400);
        }

        if ($certificationRequest->status === 'approved') {
            return response()->json([
                'success' => false,
                'message' => 'This certification request has been approved and cannot be rejected.'
            ], 400);
        }

        $certificationRequest->update([
            'status' => 'rejected',
            'rejected_at' => now(),
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
            'rejection_reason' => $request->reason,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Certification request rejected successfully.',
            'data' => $certificationRequest->load(['user', 'reviewer'])
        ]);
    }

    /**
     * Update status of a certification request (for workflow management)
     */
    public function updateStatus(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'status' => 'required|in:pending,under_review,test_pending,interview_pending,approved,rejected',
            'notes' => 'nullable|string|max:2000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $certificationRequest = CertificationRequest::findOrFail($id);
        $user = $request->user();

        $updateData = [
            'status' => $request->status,
        ];

        // Update timestamps based on status
        if ($request->status === 'under_review' && !$certificationRequest->reviewed_at) {
            $updateData['reviewed_at'] = now();
            $updateData['reviewed_by'] = $user->id;
        } elseif ($request->status === 'test_pending') {
            $updateData['test_completed_at'] = null;
        } elseif ($request->status === 'interview_pending') {
            $updateData['interview_completed_at'] = null;
        } elseif ($request->status === 'approved') {
            $updateData['approved_at'] = now();
            $updateData['reviewed_by'] = $user->id;
        } elseif ($request->status === 'rejected') {
            $updateData['rejected_at'] = now();
            $updateData['reviewed_by'] = $user->id;
        }

        if ($request->has('notes')) {
            $updateData['notes'] = $request->notes;
        }

        $certificationRequest->update($updateData);

        return response()->json([
            'success' => true,
            'message' => 'Certification request status updated successfully.',
            'data' => $certificationRequest->load(['user', 'reviewer'])
        ]);
    }
}
