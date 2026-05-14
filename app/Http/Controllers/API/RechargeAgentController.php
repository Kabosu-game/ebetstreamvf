<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AgentWallet;
use App\Models\RechargeAgent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class RechargeAgentController extends Controller
{
    public function index()
    {
        $agents = RechargeAgent::active()->get();

        return response()->json([
            'success' => true,
            'data' => $agents,
        ]);
    }

    public function adminIndex()
    {
        $agents = RechargeAgent::with(['wallet', 'user:id,username,email,role', 'tier:id,name'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(fn ($agent) => $this->formatAgent($agent));

        return response()->json([
            'success' => true,
            'data' => $agents,
        ]);
    }

    public function adminShow($id)
    {
        $agent = RechargeAgent::with(['wallet', 'user:id,username,email,role', 'tier:id,name'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $this->formatAgent($agent),
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20|unique:recharge_agents,phone',
            'status' => 'required|in:active,inactive,suspended',
            'description' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $agent = RechargeAgent::create($validator->validated());

        AgentWallet::firstOrCreate(
            ['recharge_agent_id' => $agent->id],
            ['balance' => 0, 'locked_balance' => 0, 'currency' => 'USDT']
        );

        return response()->json([
            'success' => true,
            'message' => 'Agent créé avec succès',
            'data' => $this->formatAgent($agent->fresh(['wallet', 'user', 'tier'])),
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20|unique:recharge_agents,phone,' . $id,
            'status' => 'required|in:active,inactive,suspended',
            'description' => 'nullable|string|max:1000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $agent = RechargeAgent::findOrFail($id);
        $agent->update($validator->validated());

        return response()->json([
            'success' => true,
            'message' => 'Agent mis à jour avec succès',
            'data' => $this->formatAgent($agent->fresh(['wallet', 'user', 'tier'])),
        ]);
    }

    public function adjustWallet(Request $request, $id)
    {
        $validator = Validator::make($request->all(), [
            'action' => 'required|in:set,credit,debit',
            'amount' => 'required|numeric|min:0',
            'locked_balance' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation error',
                'errors' => $validator->errors(),
            ], 422);
        }

        $agent = RechargeAgent::with('wallet')->findOrFail($id);

        DB::beginTransaction();
        try {
            $wallet = $agent->wallet ?? AgentWallet::create([
                'recharge_agent_id' => $agent->id,
                'balance' => 0,
                'locked_balance' => 0,
                'currency' => 'USDT',
            ]);

            $amount = (float) $request->amount;

            switch ($request->action) {
                case 'set':
                    $wallet->balance = $amount;
                    break;
                case 'credit':
                    $wallet->balance = (float) $wallet->balance + $amount;
                    break;
                case 'debit':
                    $wallet->balance = max(0, (float) $wallet->balance - $amount);
                    break;
            }

            if ($request->has('locked_balance')) {
                $wallet->locked_balance = (float) $request->locked_balance;
            }

            if ((float) $wallet->locked_balance > (float) $wallet->balance) {
                $wallet->locked_balance = $wallet->balance;
            }

            $wallet->save();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Solde agent mis à jour',
                'data' => $this->formatAgent($agent->fresh(['wallet', 'user', 'tier'])),
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function suspend($id)
    {
        $agent = RechargeAgent::findOrFail($id);
        $agent->update(['status' => 'suspended']);

        return response()->json([
            'success' => true,
            'message' => 'Agent suspendu',
            'data' => $this->formatAgent($agent->fresh(['wallet', 'user', 'tier'])),
        ]);
    }

    public function activate($id)
    {
        $agent = RechargeAgent::findOrFail($id);
        $agent->update(['status' => 'active']);

        return response()->json([
            'success' => true,
            'message' => 'Agent réactivé',
            'data' => $this->formatAgent($agent->fresh(['wallet', 'user', 'tier'])),
        ]);
    }

    public function destroy(Request $request, $id)
    {
        $agent = RechargeAgent::findOrFail($id);

        DB::beginTransaction();
        try {
            if ($request->boolean('revoke_user_role', true) && $agent->user_id) {
                User::where('id', $agent->user_id)->update(['role' => 'player']);
            }

            $agent->delete();
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Agent supprimé avec succès',
            ]);
        } catch (\Throwable $e) {
            DB::rollBack();
            return response()->json([
                'success' => false,
                'message' => 'Erreur lors de la suppression: ' . $e->getMessage(),
            ], 500);
        }
    }

    private function formatAgent(RechargeAgent $agent): array
    {
        $data = $agent->toArray();
        $wallet = $agent->wallet;

        $data['wallet_balance'] = $wallet ? (float) $wallet->balance : 0;
        $data['wallet_locked'] = $wallet ? (float) $wallet->locked_balance : 0;
        $data['wallet_available'] = $wallet ? $wallet->availableBalance() : 0;
        $data['wallet_currency'] = $wallet?->currency ?? 'USDT';

        return $data;
    }
}
