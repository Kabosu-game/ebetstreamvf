<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AgentTier;
use App\Models\MonetizationSetting;
use App\Models\StreamerTier;
use Illuminate\Http\Request;

class AdminMonetizationController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => [
                'settings' => MonetizationSetting::orderBy('setting_key')->get(),
                'streamer_tiers' => StreamerTier::orderBy('sort_order')->orderBy('id')->get(),
                'agent_tiers' => AgentTier::orderBy('sort_order')->orderBy('id')->get(),
            ],
        ]);
    }

    public function updateSetting(Request $request, string $settingKey)
    {
        $validated = $request->validate([
            'setting_value' => 'required|array',
        ]);

        $setting = MonetizationSetting::updateOrCreate(
            ['setting_key' => $settingKey],
            ['setting_value' => $validated['setting_value']]
        );

        return response()->json([
            'success' => true,
            'message' => 'Monetization setting updated successfully.',
            'data' => $setting,
        ]);
    }

    public function storeStreamerTier(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:streamer_tiers,name',
            'min_followers' => 'required|integer|min:0',
            'max_followers' => 'nullable|integer|min:0|gte:min_followers',
            'commission_percentage' => 'required|numeric|min:0|max:100',
            'benefits' => 'nullable|array',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $tier = StreamerTier::create([
            ...$validated,
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Streamer tier created successfully.',
            'data' => $tier,
        ], 201);
    }

    public function updateStreamerTier(Request $request, int $id)
    {
        $tier = StreamerTier::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100|unique:streamer_tiers,name,' . $id,
            'min_followers' => 'sometimes|integer|min:0',
            'max_followers' => 'nullable|integer|min:0',
            'commission_percentage' => 'sometimes|numeric|min:0|max:100',
            'benefits' => 'nullable|array',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $tier->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Streamer tier updated successfully.',
            'data' => $tier->fresh(),
        ]);
    }

    public function destroyStreamerTier(int $id)
    {
        $tier = StreamerTier::findOrFail($id);
        $tier->delete();

        return response()->json([
            'success' => true,
            'message' => 'Streamer tier deleted successfully.',
        ]);
    }

    public function storeAgentTier(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:100|unique:agent_tiers,name',
            'min_monthly_volume' => 'required|numeric|min:0',
            'deposit_commission_percentage' => 'required|numeric|min:0|max:100',
            'withdrawal_commission_percentage' => 'required|numeric|min:0|max:100',
            'requires_guarantee_amount' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $tier = AgentTier::create([
            ...$validated,
            'requires_guarantee_amount' => $validated['requires_guarantee_amount'] ?? 0,
            'is_active' => $validated['is_active'] ?? true,
            'sort_order' => $validated['sort_order'] ?? 0,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Agent tier created successfully.',
            'data' => $tier,
        ], 201);
    }

    public function updateAgentTier(Request $request, int $id)
    {
        $tier = AgentTier::findOrFail($id);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:100|unique:agent_tiers,name,' . $id,
            'min_monthly_volume' => 'sometimes|numeric|min:0',
            'deposit_commission_percentage' => 'sometimes|numeric|min:0|max:100',
            'withdrawal_commission_percentage' => 'sometimes|numeric|min:0|max:100',
            'requires_guarantee_amount' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0',
        ]);

        $tier->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Agent tier updated successfully.',
            'data' => $tier->fresh(),
        ]);
    }

    public function destroyAgentTier(int $id)
    {
        $tier = AgentTier::findOrFail($id);
        $tier->delete();

        return response()->json([
            'success' => true,
            'message' => 'Agent tier deleted successfully.',
        ]);
    }
}
