<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\RechargeAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class RechargeAgentController extends Controller
{
    /**
     * Récupérer tous les agents rechargeurs actifs
     */
    public function index()
    {
        $agents = RechargeAgent::active()->get();

        return response()->json([
            'success' => true,
            'data' => $agents
        ]);
    }

    /**
     * Créer un nouvel agent rechargeur (admin seulement)
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20|unique:recharge_agents,phone',
            'status' => 'required|in:active,inactive',
            'description' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $agent = RechargeAgent::create([
            'name' => $request->name,
            'phone' => $request->phone,
            'status' => $request->status,
            'description' => $request->description,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Agent created successfully',
            'data' => $agent
        ], 201);
    }

    /**
     * Mettre à jour un agent rechargeur (admin seulement)
     */
    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20|unique:recharge_agents,phone,' . $id,
            'status' => 'required|in:active,inactive',
            'description' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $agent = RechargeAgent::findOrFail($id);
        $agent->update([
            'name' => $request->name,
            'phone' => $request->phone,
            'status' => $request->status,
            'description' => $request->description,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Agent updated successfully',
            'data' => $agent
        ]);
    }

    /**
     * Supprimer un agent rechargeur (admin seulement)
     */
    public function destroy($id)
    {
        $agent = RechargeAgent::findOrFail($id);
        $agent->delete();

        return response()->json([
            'success' => true,
            'message' => 'Agent deleted successfully'
        ]);
    }

    /**
     * Récupérer tous les agents rechargeurs (admin seulement - inclut inactifs)
     */
    public function adminIndex()
    {
        $agents = RechargeAgent::orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => $agents
        ]);
    }
}


