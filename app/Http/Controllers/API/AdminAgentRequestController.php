<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AgentRequest;
use App\Models\RechargeAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\QueryException;

class AdminAgentRequestController extends Controller
{
    /**
     * Get all agent requests with filters
     */
    public function index(Request $request)
    {
        try {
            $query = AgentRequest::with(['user:id,username,email']);

            // Filter by status
            if ($request->has('status') && $request->status) {
                $query->where('status', $request->status);
            }

            // Search by name or whatsapp
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('whatsapp', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            $requests = $query->orderBy('created_at', 'desc')
                ->paginate($request->get('per_page', 20));

            return response()->json([
                'success' => true,
                'data' => $requests
            ]);
        } catch (QueryException $e) {
            // Table might not exist yet
            if (strpos($e->getMessage(), "doesn't exist") !== false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Agent requests table does not exist. Please run migrations: php artisan migrate'
                ], 500);
            }
            throw $e;
        }
    }

    /**
     * Get a specific agent request
     */
    public function show($id)
    {
        try {
            $request = AgentRequest::with(['user:id,username,email'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $request
            ]);
        } catch (QueryException $e) {
            if (strpos($e->getMessage(), "doesn't exist") !== false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Agent requests table does not exist. Please run migrations: php artisan migrate'
                ], 500);
            }
            throw $e;
        }
    }

    /**
     * Approve an agent request
     */
    public function approve(Request $request, $id)
    {
        try {
            $agentRequest = AgentRequest::findOrFail($id);

            if ($agentRequest->status === 'approved') {
                return response()->json([
                    'success' => false,
                    'message' => 'This agent request is already approved.'
                ], 400);
            }

            DB::beginTransaction();

            // Update request status
            $agentRequest->update([
                'status' => 'approved',
            ]);

            // Create or update RechargeAgent
            $rechargeAgent = RechargeAgent::updateOrCreate(
                [
                    'phone' => $agentRequest->whatsapp,
                ],
                [
                    'name' => $agentRequest->name,
                    'phone' => $agentRequest->whatsapp,
                    'status' => 'active',
                    'description' => $agentRequest->message ?? 'Agent de recharge approuvé',
                ]
            );

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Agent request approved successfully',
                'data' => [
                    'request' => $agentRequest->fresh(),
                    'recharge_agent' => $rechargeAgent
                ]
            ]);
        } catch (QueryException $e) {
            DB::rollBack();
            if (strpos($e->getMessage(), "doesn't exist") !== false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Required tables do not exist. Please run migrations: php artisan migrate'
                ], 500);
            }
            throw $e;
        }
    }

    /**
     * Reject an agent request
     */
    public function reject(Request $request, $id)
    {
        try {
            $agentRequest = AgentRequest::findOrFail($id);

            if ($agentRequest->status === 'rejected') {
                return response()->json([
                    'success' => false,
                    'message' => 'This agent request is already rejected.'
                ], 400);
            }

            $agentRequest->update([
                'status' => 'rejected',
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Agent request rejected successfully',
                'data' => $agentRequest->fresh()
            ]);
        } catch (QueryException $e) {
            if (strpos($e->getMessage(), "doesn't exist") !== false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Agent requests table does not exist. Please run migrations: php artisan migrate'
                ], 500);
            }
            throw $e;
        }
    }

    /**
     * Update agent request status
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $validator = Validator::make($request->all(), [
                'status' => 'required|in:pending,approved,rejected',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Validation error',
                    'errors' => $validator->errors()
                ], 422);
            }

            $agentRequest = AgentRequest::findOrFail($id);
            $oldStatus = $agentRequest->status;
            $newStatus = $request->status;

            DB::beginTransaction();

            $agentRequest->update([
                'status' => $newStatus,
            ]);

            // If approving, create/update RechargeAgent
            if ($newStatus === 'approved' && $oldStatus !== 'approved') {
                $rechargeAgent = RechargeAgent::updateOrCreate(
                    [
                        'phone' => $agentRequest->whatsapp,
                    ],
                    [
                        'name' => $agentRequest->name,
                        'phone' => $agentRequest->whatsapp,
                        'status' => 'active',
                        'description' => $agentRequest->message ?? 'Agent de recharge approuvé',
                    ]
                );
            }

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Agent request status updated successfully',
                'data' => $agentRequest->fresh()
            ]);
        } catch (QueryException $e) {
            DB::rollBack();
            if (strpos($e->getMessage(), "doesn't exist") !== false) {
                return response()->json([
                    'success' => false,
                    'message' => 'Required tables do not exist. Please run migrations: php artisan migrate'
                ], 500);
            }
            throw $e;
        }
    }
}





