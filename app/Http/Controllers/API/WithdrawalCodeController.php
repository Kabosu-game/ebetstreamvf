<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\WithdrawalCode;
use App\Models\RechargeAgent;
use App\Services\AgentCryptoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;

class WithdrawalCodeController extends Controller
{
    public function __construct(private AgentCryptoService $agentCryptoService) {}

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1|max:1000000',
            'recharge_agent_id' => 'required|exists:recharge_agents,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'message' => 'Validation error', 'errors' => $validator->errors()], 422);
        }

        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $agent = RechargeAgent::find($request->recharge_agent_id);
        if (!$agent || $agent->status !== 'active') {
            return response()->json(['success' => false, 'message' => 'Agent non disponible'], 400);
        }

        $amountEbt = (float) $request->amount;

        try {
            $this->agentCryptoService->lockPlayerFundsForWithdrawal($user, $amountEbt);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        $code = WithdrawalCode::generateUniqueCode();

        $withdrawalCode = WithdrawalCode::create([
            'code' => $code,
            'amount' => $amountEbt,
            'user_id' => $user->id,
            'recharge_agent_id' => $request->recharge_agent_id,
            'status' => 'pending',
            'expires_at' => Carbon::now()->addHours(24),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Code de retrait généré — montant bloqué sur votre compte',
            'data' => [
                'code' => $withdrawalCode->code,
                'amount' => $withdrawalCode->amount,
                'amount_ebt' => $amountEbt,
                'agent_name' => $agent->name,
                'agent_phone' => $agent->phone,
                'agent_id' => $agent->agent_id,
                'expires_at' => $withdrawalCode->expires_at,
                'instructions' => [
                    '1. Contactez l\'agent : ' . $agent->phone,
                    '2. Communiquez le code : ' . $withdrawalCode->code,
                    '3. Montant : ' . number_format($amountEbt, 2) . ' EBT',
                    '4. L\'agent valide la transaction dans son tableau de bord',
                    '5. Code valide 24 heures',
                ],
            ],
        ], 201);
    }

    public function index(Request $request)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $codes = WithdrawalCode::with(['rechargeAgent:id,name,phone,agent_id,rating_avg'])
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json(['success' => true, 'data' => $codes]);
    }

    public function show(Request $request, $code)
    {
        $withdrawalCode = WithdrawalCode::with(['rechargeAgent:id,name,phone', 'user:id,username,email'])
            ->where('code', $code)
            ->firstOrFail();

        return response()->json(['success' => true, 'data' => $withdrawalCode]);
    }

    public function complete(Request $request, $code)
    {
        $agent = $this->agentCryptoService->getAgentForUser($request->user());
        if (!$agent) {
            return response()->json(['success' => false, 'message' => 'Accès agent requis'], 403);
        }

        $withdrawalCode = WithdrawalCode::where('code', $code)
            ->where('recharge_agent_id', $agent->id)
            ->where('status', 'pending')
            ->firstOrFail();

        if ($withdrawalCode->expires_at && $withdrawalCode->expires_at->isPast()) {
            return response()->json(['success' => false, 'message' => 'Code expiré'], 400);
        }

        try {
            $this->agentCryptoService->completeWithdrawalViaAgent($withdrawalCode, $agent);
            $withdrawalCode->update([
                'status' => 'completed',
                'completed_at' => now(),
                'notes' => $request->get('notes'),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Retrait complété',
                'data' => $withdrawalCode->fresh(),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function cancel(Request $request, $code)
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['success' => false, 'message' => 'Unauthorized'], 401);
        }

        $withdrawalCode = WithdrawalCode::where('code', $code)
            ->where('user_id', $user->id)
            ->where('status', 'pending')
            ->firstOrFail();

        $this->agentCryptoService->releaseLockedFunds($withdrawalCode);

        $withdrawalCode->update(['status' => 'cancelled', 'notes' => 'Annulé par le joueur']);

        return response()->json(['success' => true, 'message' => 'Code annulé — fonds débloqués', 'data' => $withdrawalCode->fresh()]);
    }

    public function getActiveAgents()
    {
        $agents = RechargeAgent::active()
            ->whereNotNull('user_id')
            ->get(['id', 'agent_id', 'name', 'phone', 'description', 'rating_avg', 'rating_count']);

        return response()->json(['success' => true, 'data' => $agents]);
    }

    public function adminIndex(Request $request)
    {
        $query = WithdrawalCode::with(['user', 'rechargeAgent']);

        if ($request->has('status') && $request->status) {
            $query->where('status', $request->status);
        }
        if ($request->has('agent_id') && $request->agent_id) {
            $query->where('recharge_agent_id', $request->agent_id);
        }

        $codes = $query->orderBy('created_at', 'desc')->get();

        return response()->json([
            'success' => true,
            'data' => [
                'codes' => $codes,
                'stats' => [
                    'total' => WithdrawalCode::count(),
                    'pending' => WithdrawalCode::where('status', 'pending')->count(),
                    'completed' => WithdrawalCode::where('status', 'completed')->count(),
                ],
            ],
        ]);
    }

    public function adminComplete($id)
    {
        $withdrawalCode = WithdrawalCode::findOrFail($id);
        if ($withdrawalCode->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Code non en attente'], 400);
        }

        $agent = RechargeAgent::findOrFail($withdrawalCode->recharge_agent_id);
        $this->agentCryptoService->completeWithdrawalViaAgent($withdrawalCode, $agent);
        $withdrawalCode->update(['status' => 'completed', 'completed_at' => now(), 'notes' => 'Complété par admin']);

        return response()->json(['success' => true, 'data' => $withdrawalCode->fresh(['user', 'rechargeAgent'])]);
    }

    public function adminCancel($id)
    {
        $withdrawalCode = WithdrawalCode::findOrFail($id);
        if ($withdrawalCode->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Code non en attente'], 400);
        }

        $this->agentCryptoService->releaseLockedFunds($withdrawalCode);
        $withdrawalCode->update(['status' => 'cancelled', 'notes' => 'Annulé par admin']);

        return response()->json(['success' => true, 'data' => $withdrawalCode->fresh(['user', 'rechargeAgent'])]);
    }
}
