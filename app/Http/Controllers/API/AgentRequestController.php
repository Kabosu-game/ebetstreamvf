<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AgentRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AgentRequestController extends Controller
{
    /**
     * Submit an agent request (public route)
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $userId = $user ? $user->id : null;

        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'whatsapp' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:20',
            'birth_date' => 'nullable|date',
            'city' => 'nullable|string|max:255',
            'occupation' => 'nullable|string|max:255',
            'experience' => 'nullable|in:beginner,intermediate,advanced,expert',
            'skills' => 'nullable|string|max:1000',
            'availability' => 'nullable|in:full-time,part-time,weekends,flexible',
            'working_hours' => 'nullable|string|max:255',
            'motivation' => 'required|string|max:1000',
            'message' => 'nullable|string|max:1000',
            'has_id_card' => 'nullable|in:yes,no',
            'has_business_license' => 'nullable|in:yes,no',
            'agree_terms' => 'required|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $agentRequest = AgentRequest::create([
            'name' => $request->name,
            'whatsapp' => $request->whatsapp,
            'email' => $request->email,
            'phone' => $request->phone,
            'birth_date' => $request->birth_date,
            'city' => $request->city,
            'occupation' => $request->occupation,
            'experience' => $request->experience,
            'skills' => $request->skills,
            'availability' => $request->availability,
            'working_hours' => $request->working_hours,
            'motivation' => $request->motivation,
            'message' => $request->message,
            'has_id_card' => $request->has_id_card,
            'has_business_license' => $request->has_business_license,
            'agree_terms' => $request->agree_terms,
            'status' => 'pending',
            'user_id' => $userId,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Agent request submitted successfully',
            'data' => $agentRequest
        ], 201);
    }

    /**
     * Get user's agent requests (authenticated users only)
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $requests = AgentRequest::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $requests
        ]);
    }

    /**
     * Get a specific agent request (authenticated users only)
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $agentRequest = AgentRequest::where('user_id', $user->id)
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $agentRequest
        ]);
    }
}





