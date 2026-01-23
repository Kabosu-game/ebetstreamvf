<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WithdrawalCode;
use App\Models\RechargeAgent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class WithdrawalCodeController extends Controller
{
    /**
     * Créer un code de retrait
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:100|max:1000000', // Montant entre 100 et 1M
            'recharge_agent_id' => 'required|exists:recharge_agents,id',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        // Vérifier que l'agent est actif
        $agent = RechargeAgent::find($request->recharge_agent_id);
        if (!$agent || $agent->status !== 'active') {
            return response()->json([
                'success' => false,
                'message' => 'Selected agent is not available'
            ], 400);
        }

        // Générer le code unique
        $code = WithdrawalCode::generateUniqueCode();
        
        // Créer le code de retrait
        $withdrawalCode = WithdrawalCode::create([
            'code' => $code,
            'amount' => $request->amount,
            'user_id' => $user->id,
            'recharge_agent_id' => $request->recharge_agent_id,
            'status' => 'pending',
            'expires_at' => Carbon::now()->addHours(24), // Expire dans 24h
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal code generated successfully',
            'data' => [
                'code' => $withdrawalCode->code,
                'amount' => $withdrawalCode->amount,
                'agent_name' => $agent->name,
                'agent_phone' => $agent->phone,
                'expires_at' => $withdrawalCode->expires_at,
                'instructions' => [
                    '1. Contact the agent via WhatsApp: ' . $agent->phone,
                    '2. Send this withdrawal code: ' . $withdrawalCode->code,
                    '3. Specify the amount: ' . number_format($withdrawalCode->amount, 2) . ' XAF',
                    '4. The agent will verify and process your withdrawal',
                    '5. Code expires in 24 hours'
                ]
            ]
        ], 201);
    }

    /**
     * Lister les codes de retrait de l'utilisateur
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

        $codes = WithdrawalCode::with(['rechargeAgent:id,name,phone'])
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json([
            'success' => true,
            'data' => $codes
        ]);
    }

    /**
     * Obtenir les détails d'un code de retrait
     */
    public function show(Request $request, $code)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $withdrawalCode = WithdrawalCode::with(['rechargeAgent:id,name,phone', 'user:id,username,email'])
            ->where('code', $code)
            ->firstOrFail();

        return response()->json([
            'success' => true,
            'data' => $withdrawalCode
        ]);
    }

    /**
     * Marquer un code comme complété (pour les agents)
     */
    public function complete(Request $request, $code)
    {
        $validator = Validator::make($request->all(), [
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors()
            ], 422);
        }

        $withdrawalCode = WithdrawalCode::where('code', $code)
            ->where('status', 'pending')
            ->firstOrFail();

        // Vérifier que le code n'est pas expiré
        if ($withdrawalCode->expires_at && $withdrawalCode->expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'message' => 'Withdrawal code has expired'
            ], 400);
        }

        $withdrawalCode->update([
            'status' => 'completed',
            'completed_at' => now(),
            'notes' => $request->notes,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal completed successfully',
            'data' => $withdrawalCode->fresh()
        ]);
    }

    /**
     * Annuler un code de retrait
     */
    public function cancel(Request $request, $code)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $withdrawalCode = WithdrawalCode::where('code', $code)
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->firstOrFail();

        $withdrawalCode->update([
            'status' => 'cancelled',
            'notes' => 'Cancelled by user'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal code cancelled successfully',
            'data' => $withdrawalCode->fresh()
        ]);
    }

    /**
     * Obtenir les agents actifs pour le retrait
     */
    public function getActiveAgents()
    {
        $agents = RechargeAgent::active()->get(['id', 'agent_id', 'name', 'phone', 'description']);

        return response()->json([
            'success' => true,
            'data' => $agents
        ]);
    }

    // ========== ADMIN METHODS ==========

    /**
     * Obtenir tous les codes de retrait (admin)
     */
    public function adminIndex(Request $request)
    {
        $query = WithdrawalCode::with(['user', 'rechargeAgent']);

        // Filtrage par statut
        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }

        // Filtrage par agent
        if ($request->has('agent_id') && $request->agent_id) {
            $query->where('recharge_agent_id', $request->agent_id);
        }

        // Filtrage par date
        if ($request->has('date_from') && $request->date_from) {
            $query->whereDate('created_at', '>=', $request->date_from);
        }

        if ($request->has('date_to') && $request->date_to) {
            $query->whereDate('created_at', '<=', $request->date_to);
        }

        $codes = $query->orderBy('created_at', 'desc')->get();

        // Statistiques
        $stats = [
            'total' => WithdrawalCode::count(),
            'pending' => WithdrawalCode::where('status', 'pending')->count(),
            'completed' => WithdrawalCode::where('status', 'completed')->count(),
            'expired' => WithdrawalCode::where('status', 'expired')->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'codes' => $codes,
                'stats' => $stats
            ]
        ]);
    }

    /**
     * Marquer un code comme complété (admin)
     */
    public function adminComplete($id)
    {
        $withdrawalCode = WithdrawalCode::findOrFail($id);
        
        if ($withdrawalCode->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending codes can be completed'
            ], 400);
        }

        $withdrawalCode->status = 'completed';
        $withdrawalCode->completed_at = now();
        $withdrawalCode->notes = 'Completed by admin';
        $withdrawalCode->save();

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal code marked as completed',
            'data' => $withdrawalCode->fresh(['user', 'rechargeAgent'])
        ]);
    }

    /**
     * Annuler un code (admin)
     */
    public function adminCancel($id)
    {
        $withdrawalCode = WithdrawalCode::findOrFail($id);
        
        if ($withdrawalCode->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending codes can be cancelled'
            ], 400);
        }

        $withdrawalCode->status = 'cancelled';
        $withdrawalCode->notes = 'Cancelled by admin';
        $withdrawalCode->save();

        return response()->json([
            'success' => true,
            'message' => 'Withdrawal code cancelled',
            'data' => $withdrawalCode->fresh(['user', 'rechargeAgent'])
        ]);
    }
}
