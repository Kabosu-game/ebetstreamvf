<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AgentCryptoDeposit;
use App\Models\AgentRating;
use App\Models\AgentTransfer;
use App\Models\User;
use App\Models\WithdrawalCode;
use App\Services\AgentCryptoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AgentCryptoController extends Controller
{
    public function __construct(private AgentCryptoService $service) {}

    public function dashboard(Request $request)
    {
        $agent = $this->service->getAgentForUser($request->user());
        if (!$agent) {
            return response()->json(['success' => false, 'message' => 'Compte agent non trouvé'], 403);
        }

        $agent->load(['wallet', 'tier']);

        $todayTransfers = AgentTransfer::where('recharge_agent_id', $agent->id)
            ->whereDate('created_at', today())
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->pluck('total', 'type');

        $pendingWithdrawals = WithdrawalCode::with('user:id,username')
            ->where('recharge_agent_id', $agent->id)
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'success' => true,
            'data' => [
                'agent' => $agent,
                'wallet' => $agent->wallet,
                'limits' => $this->service->getLimits(),
                'today_transfers' => $todayTransfers,
                'pending_withdrawals' => $pendingWithdrawals,
            ],
        ]);
    }

    public function requestCryptoDeposit(Request $request)
    {
        $agent = $this->service->getAgentForUser($request->user());
        if (!$agent) {
            return response()->json(['success' => false, 'message' => 'Compte agent requis'], 403);
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:1',
            'tx_hash' => 'required|string|max:128',
            'crypto_network' => 'nullable|string|max:30',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        try {
            $deposit = $this->service->requestCryptoDeposit(
                $agent,
                (float) $request->amount,
                $request->tx_hash,
                $request->get('crypto_network', 'USDT TRC20')
            );

            return response()->json([
                'success' => true,
                'message' => 'Demande de recharge soumise — validation admin sous 24h',
                'data' => $deposit,
            ], 201);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function depositToPlayer(Request $request)
    {
        $agent = $this->service->getAgentForUser($request->user());
        if (!$agent) {
            return response()->json(['success' => false, 'message' => 'Compte agent requis'], 403);
        }

        $validator = Validator::make($request->all(), [
            'player_identifier' => 'required|string',
            'amount' => 'required|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $player = User::where('username', $request->player_identifier)
            ->orWhere('email', $request->player_identifier)
            ->first();

        if (!$player) {
            return response()->json(['success' => false, 'message' => 'Joueur introuvable'], 404);
        }

        try {
            $transfer = $this->service->depositToPlayer($agent, $player, (float) $request->amount);

            return response()->json([
                'success' => true,
                'message' => 'Dépôt interne effectué — solde joueur crédité',
                'data' => $transfer->load('user:id,username'),
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function completeWithdrawal(Request $request, $code)
    {
        $agent = $this->service->getAgentForUser($request->user());
        if (!$agent) {
            return response()->json(['success' => false, 'message' => 'Compte agent requis'], 403);
        }

        $withdrawalCode = WithdrawalCode::where('code', $code)
            ->where('recharge_agent_id', $agent->id)
            ->where('status', 'pending')
            ->firstOrFail();

        if ($withdrawalCode->expires_at && $withdrawalCode->expires_at->isPast()) {
            return response()->json(['success' => false, 'message' => 'Code expiré'], 400);
        }

        try {
            $transfer = $this->service->completeWithdrawalViaAgent($withdrawalCode, $agent);
            $withdrawalCode->update([
                'status' => 'completed',
                'completed_at' => now(),
                'notes' => $request->get('notes'),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Retrait validé — fonds crédités sur votre solde agent',
                'data' => ['transfer' => $transfer, 'code' => $withdrawalCode->fresh()],
            ]);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function transfers(Request $request)
    {
        $agent = $this->service->getAgentForUser($request->user());
        if (!$agent) {
            return response()->json(['success' => false, 'message' => 'Compte agent requis'], 403);
        }

        $transfers = AgentTransfer::with('user:id,username')
            ->where('recharge_agent_id', $agent->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return response()->json(['success' => true, 'data' => $transfers]);
    }

    public function rateAgent(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'recharge_agent_id' => 'required|exists:recharge_agents,id',
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $rating = AgentRating::updateOrCreate(
            [
                'recharge_agent_id' => $request->recharge_agent_id,
                'user_id' => $request->user()->id,
            ],
            ['rating' => $request->rating, 'comment' => $request->comment]
        );

        $agent = \App\Models\RechargeAgent::find($request->recharge_agent_id);
        $stats = AgentRating::where('recharge_agent_id', $agent->id);
        $agent->update([
            'rating_avg' => $stats->avg('rating'),
            'rating_count' => $stats->count(),
        ]);

        return response()->json(['success' => true, 'data' => $rating]);
    }

    // Admin: approve crypto deposit
    public function adminApproveDeposit($id)
    {
        $deposit = AgentCryptoDeposit::findOrFail($id);
        if ($deposit->status !== 'pending') {
            return response()->json(['success' => false, 'message' => 'Dépôt déjà traité'], 400);
        }

        $this->service->approveCryptoDeposit($deposit);

        return response()->json(['success' => true, 'message' => 'Solde agent crédité', 'data' => $deposit->fresh()]);
    }

    public function adminListDeposits()
    {
        $deposits = AgentCryptoDeposit::with('agent:id,name,agent_id')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $deposits]);
    }

    public function publicAgents()
    {
        $agents = \App\Models\RechargeAgent::active()
            ->with('tier:id,name')
            ->whereNotNull('user_id')
            ->get(['id', 'agent_id', 'name', 'phone', 'description', 'rating_avg', 'rating_count', 'agent_tier_id']);

        return response()->json(['success' => true, 'data' => $agents]);
    }
}
