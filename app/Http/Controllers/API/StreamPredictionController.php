<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Stream;
use App\Models\StreamPrediction;
use App\Models\MonetizationSetting;
use App\Services\MonetizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class StreamPredictionController extends Controller
{
    /**
     * POST /streams/{id}/predict
     * Spectateur supporte un streamer avec des crédits.
     * Commission = credits * commission_rate%
     * Streamer reçoit 40% de la commission, plateforme 60%
     */
    public function predict(Request $request, int $streamId)
    {
        $predictor = $request->user();

        $v = Validator::make($request->all(), [
            'credits_amount' => 'required|numeric|min:1|max:50000',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'errors' => $v->errors()], 422);
        }

        $stream = Stream::findOrFail($streamId);

        if (!$stream->is_live) {
            return response()->json(['success' => false, 'message' => 'Le stream doit être en direct'], 422);
        }

        if ($stream->user_id === $predictor->id) {
            return response()->json(['success' => false, 'message' => 'Cannot predict your own stream'], 422);
        }

        $creditsAmount = (float) $request->credits_amount;

        // Read commission split settings
        $split          = MonetizationSetting::where('setting_key', 'support_prediction_commission_split')
                          ->value('setting_value')
                          ?? ['commission_rate' => 15, 'streamer_percent' => 40, 'platform_percent' => 60];

        $commissionRate  = (float) ($split['commission_rate']  ?? 15);
        $streamerPct     = (float) ($split['streamer_percent'] ?? 40);
        $platformPct     = (float) ($split['platform_percent'] ?? 60);

        $commission      = round($creditsAmount * $commissionRate / 100, 2);
        $streamerShare   = round($commission * $streamerPct / 100, 2);
        $platformShare   = round($commission - $streamerShare, 2);
        // Predictor "spends" only the commission (credits - commission = their net stake)
        $predicatorCost  = $commission; // they pay the commission; the rest stays "staked"

        try {
            DB::transaction(function () use (
                $predictor, $stream, $creditsAmount, $commission,
                $streamerShare, $platformShare, $commissionRate, $streamerPct
            ) {
                // Debit commission from predictor
                MonetizationService::debitWallet($predictor->id, $commission);

                // Credit streamer their share
                MonetizationService::creditWallet($stream->user_id, $streamerShare);

                // Credit platform
                MonetizationService::creditWallet(MonetizationService::platformUserId(), $platformShare);

                StreamPrediction::create([
                    'stream_id'           => $stream->id,
                    'predictor_user_id'   => $predictor->id,
                    'streamer_user_id'    => $stream->user_id,
                    'credits_amount'      => $creditsAmount,
                    'platform_commission' => $commission,
                    'streamer_share'      => $streamerShare,
                    'platform_share'      => $platformShare,
                    'commission_rate'     => $commissionRate,
                    'streamer_percent'    => $streamerPct,
                    'prediction_type'     => 'support',
                    'status'              => 'active',
                ]);
            });
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        return response()->json([
            'success' => true,
            'message' => "Support de {$creditsAmount} crédits envoyé !",
            'data'    => [
                'credits_amount'  => $creditsAmount,
                'commission_paid' => $commission,
                'streamer_earned' => $streamerShare,
                'commission_rate' => $commissionRate,
            ],
        ]);
    }

    /**
     * GET /streams/{id}/predictions
     * Stats prédictions d'un stream (live)
     */
    public function streamStats(int $streamId)
    {
        $total   = StreamPrediction::where('stream_id', $streamId)->sum('credits_amount');
        $count   = StreamPrediction::where('stream_id', $streamId)->count();
        $streamer= StreamPrediction::where('stream_id', $streamId)->sum('streamer_share');

        return response()->json([
            'success' => true,
            'data'    => [
                'total_credits'    => $total,
                'supporter_count'  => $count,
                'streamer_earned'  => $streamer,
            ],
        ]);
    }
}
