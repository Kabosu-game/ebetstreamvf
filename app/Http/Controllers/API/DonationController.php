<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\MonetizationSetting;
use App\Models\Stream;
use App\Models\StreamDonation;
use App\Services\MonetizationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class DonationController extends Controller
{
    /**
     * POST /streams/{id}/donate
     * Spectateur envoie une donation → split automatique 85/15 (ou tier streamer)
     */
    public function donate(Request $request, int $streamId)
    {
        $donor = $request->user();

        $v = Validator::make($request->all(), [
            'amount'  => 'required|numeric|min:1|max:10000',
            'message' => 'nullable|string|max:500',
        ]);
        if ($v->fails()) {
            return response()->json(['success' => false, 'errors' => $v->errors()], 422);
        }

        $stream = Stream::findOrFail($streamId);

        if ($stream->user_id === $donor->id) {
            return response()->json(['success' => false, 'message' => 'Cannot donate to your own stream'], 422);
        }

        $amount = (float) $request->amount;

        // Resolve split percentages
        $split          = MonetizationSetting::where('setting_key', 'donation_split')->value('setting_value')
                          ?? ['streamer_percent' => 85, 'platform_percent' => 15];
        $streamerPct    = (float) ($split['streamer_percent'] ?? 85);
        $platformPct    = (float) ($split['platform_percent'] ?? 15);

        $streamerAmount = round($amount * $streamerPct / 100, 2);
        $platformAmount = round($amount - $streamerAmount, 2);

        try {
            DB::transaction(function () use (
                $donor, $stream, $amount, $streamerAmount, $platformAmount,
                $streamerPct, $request
            ) {
                // Debit donor
                MonetizationService::debitWallet($donor->id, $amount);

                // Credit streamer
                MonetizationService::creditWallet($stream->user_id, $streamerAmount);

                // Credit platform
                MonetizationService::creditWallet(MonetizationService::platformUserId(), $platformAmount);

                // Record donation
                StreamDonation::create([
                    'stream_id'        => $stream->id,
                    'donor_user_id'    => $donor->id,
                    'streamer_user_id' => $stream->user_id,
                    'amount'           => $amount,
                    'streamer_amount'  => $streamerAmount,
                    'platform_amount'  => $platformAmount,
                    'streamer_percent' => $streamerPct,
                    'message'          => $request->message,
                    'status'           => 'completed',
                ]);
            });
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }

        return response()->json([
            'success' => true,
            'message' => "Donation de {$amount} EBT envoyée avec succès.",
            'data'    => [
                'amount'          => $amount,
                'streamer_amount' => $streamerAmount,
                'platform_amount' => $platformAmount,
                'streamer_pct'    => $streamerPct,
            ],
        ]);
    }

    /**
     * GET /streams/{id}/donations
     * Historique des donations d'un stream
     */
    public function index(Request $request, int $streamId)
    {
        $stream    = Stream::findOrFail($streamId);
        $donations = StreamDonation::with(['donor:id,username'])
            ->where('stream_id', $streamId)
            ->where('status', 'completed')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn($d) => [
                'id'             => $d->id,
                'donor'          => $d->donor?->username ?? 'Anonyme',
                'amount'         => $d->amount,
                'message'        => $d->message,
                'created_at'     => $d->created_at,
            ]);

        return response()->json(['success' => true, 'data' => $donations]);
    }

    /**
     * GET /dashboard/donations
     * Revenus donations du streamer connecté
     */
    public function myEarnings(Request $request)
    {
        $user = $request->user();

        $total = StreamDonation::where('streamer_user_id', $user->id)
            ->where('status', 'completed')
            ->sum('streamer_amount');

        $recent = StreamDonation::with(['donor:id,username', 'stream:id,title'])
            ->where('streamer_user_id', $user->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'total_earned' => $total,
                'recent'       => $recent,
            ],
        ]);
    }
}
