<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('monetization_settings', function (Blueprint $table) {
            $table->id();
            $table->string('setting_key')->unique();
            $table->json('setting_value')->nullable();
            $table->timestamps();
        });

        Schema::create('streamer_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedBigInteger('min_followers')->default(0);
            $table->unsignedBigInteger('max_followers')->nullable();
            $table->decimal('commission_percentage', 5, 2)->default(0);
            $table->json('benefits')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('agent_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('min_monthly_volume', 14, 2)->default(0);
            $table->decimal('deposit_commission_percentage', 5, 2)->default(0);
            $table->decimal('withdrawal_commission_percentage', 5, 2)->default(0);
            $table->decimal('requires_guarantee_amount', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::table('monetization_settings')->insert([
            [
                'setting_key' => 'donation_split',
                'setting_value' => json_encode([
                    'streamer_percent' => 85,
                    'platform_percent' => 15,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'setting_key' => 'support_prediction_commission_split',
                'setting_value' => json_encode([
                    'streamer_percent' => 40,
                    'platform_percent' => 60,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'setting_key' => 'sponsored_match_split',
                'setting_value' => json_encode([
                    'prize_pool_percent' => 60,
                    'organizer_streamer_percent' => 20,
                    'platform_percent' => 20,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'setting_key' => 'agent_limits',
                'setting_value' => json_encode([
                    'daily_deposit_limit' => 5000,
                    'daily_withdrawal_limit' => 5000,
                    'daily_internal_transfer_limit' => 10000,
                    'minimum_agent_reload' => 100,
                    'recommended_network' => 'USDT TRC20',
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('streamer_tiers')->insert([
            [
                'name' => 'Rookie',
                'min_followers' => 0,
                'max_followers' => 1000,
                'commission_percentage' => 30,
                'benefits' => json_encode(['base_visibility']),
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Pro',
                'min_followers' => 1001,
                'max_followers' => 10000,
                'commission_percentage' => 40,
                'benefits' => json_encode(['priority_support']),
                'is_active' => true,
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Elite',
                'min_followers' => 10001,
                'max_followers' => null,
                'commission_percentage' => 50,
                'benefits' => json_encode(['verified_badge', 'internal_promotion']),
                'is_active' => true,
                'sort_order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        DB::table('agent_tiers')->insert([
            [
                'name' => 'Bronze',
                'min_monthly_volume' => 0,
                'deposit_commission_percentage' => 2.00,
                'withdrawal_commission_percentage' => 1.50,
                'requires_guarantee_amount' => 100,
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Silver',
                'min_monthly_volume' => 5000,
                'deposit_commission_percentage' => 2.25,
                'withdrawal_commission_percentage' => 1.60,
                'requires_guarantee_amount' => 300,
                'is_active' => true,
                'sort_order' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'Gold',
                'min_monthly_volume' => 20000,
                'deposit_commission_percentage' => 2.50,
                'withdrawal_commission_percentage' => 1.75,
                'requires_guarantee_amount' => 1000,
                'is_active' => true,
                'sort_order' => 3,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tiers');
        Schema::dropIfExists('streamer_tiers');
        Schema::dropIfExists('monetization_settings');
    }
};
