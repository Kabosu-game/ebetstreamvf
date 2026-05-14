<?php

namespace App\Services;

use App\Models\MonetizationSetting;
use App\Models\StreamerTier;
use App\Models\Wallet;
use Illuminate\Support\Facades\DB;

class MonetizationService
{
    // ── Read settings from DB (with fallback defaults) ───────────────────────

    public static function donationSplit(): array
    {
        $s = MonetizationSetting::where('setting_key', 'donation_split')->first();
        return $s ? $s->setting_value : ['streamer_percent' => 85, 'platform_percent' => 15];
    }

    public static function predictionCommissionSplit(): array
    {
        $s = MonetizationSetting::where('setting_key', 'support_prediction_commission_split')->first();
        return $s ? $s->setting_value : ['streamer_percent' => 40, 'platform_percent' => 60, 'commission_rate' => 15];
    }

    public static function sponsoredMatchSplit(): array
    {
        $s = MonetizationSetting::where('setting_key', 'sponsored_match_split')->first();
        return $s ? $s->setting_value : [
            'prize_pool_percent' => 60,
            'organizer_streamer_percent' => 20,
            'platform_percent' => 20,
        ];
    }

    // ── Resolve streamer tier by follower count ───────────────────────────────

    public static function resolveStreamerTier(int $followerCount): ?StreamerTier
    {
        return StreamerTier::where('is_active', true)
            ->where('min_followers', '<=', $followerCount)
            ->where(function ($q) use ($followerCount) {
                $q->whereNull('max_followers')
                  ->orWhere('max_followers', '>=', $followerCount);
            })
            ->orderByDesc('min_followers')
            ->first();
    }

    // ── Credit wallet safely (DB transaction) ────────────────────────────────

    public static function creditWallet(int $userId, float $amount, string $currency = 'EBT'): Wallet
    {
        return DB::transaction(function () use ($userId, $amount, $currency) {
            $wallet = Wallet::firstOrCreate(
                ['user_id' => $userId],
                ['balance' => 0, 'locked_balance' => 0, 'currency' => $currency]
            );
            $wallet->increment('balance', $amount);
            return $wallet->fresh();
        });
    }

    public static function debitWallet(int $userId, float $amount): Wallet
    {
        return DB::transaction(function () use ($userId, $amount) {
            $wallet = Wallet::where('user_id', $userId)->lockForUpdate()->firstOrFail();
            $available = $wallet->balance - $wallet->locked_balance;
            if ($available < $amount) {
                throw new \Exception('Insufficient balance');
            }
            $wallet->decrement('balance', $amount);
            return $wallet->fresh();
        });
    }

    // ── Platform wallet (user_id = 1 or env PLATFORM_WALLET_USER_ID) ─────────

    public static function platformUserId(): int
    {
        return (int) env('PLATFORM_WALLET_USER_ID', 1);
    }
}
