<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('recharge_agents', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->foreignId('agent_tier_id')->nullable()->after('user_id')->constrained('agent_tiers')->nullOnDelete();
            $table->boolean('kyc_verified')->default(false)->after('status');
            $table->timestamp('contract_signed_at')->nullable()->after('kyc_verified');
            $table->decimal('rating_avg', 3, 2)->default(0)->after('contract_signed_at');
            $table->unsignedInteger('rating_count')->default(0)->after('rating_avg');
        });

        Schema::create('agent_wallets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recharge_agent_id')->unique()->constrained()->cascadeOnDelete();
            $table->decimal('balance', 14, 2)->default(0);
            $table->decimal('locked_balance', 14, 2)->default(0);
            $table->decimal('guarantee_deposit', 14, 2)->default(0);
            $table->decimal('total_deposited', 14, 2)->default(0);
            $table->decimal('total_transferred', 14, 2)->default(0);
            $table->string('currency', 10)->default('USDT');
            $table->timestamps();
        });

        Schema::create('agent_crypto_deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recharge_agent_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('crypto_network', 30)->default('USDT TRC20');
            $table->string('tx_hash')->nullable();
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->text('admin_notes')->nullable();
            $table->timestamp('credited_at')->nullable();
            $table->timestamps();
        });

        Schema::create('agent_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recharge_agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['deposit_to_player', 'withdrawal_from_player']);
            $table->decimal('amount', 14, 2);
            $table->decimal('commission', 14, 2)->default(0);
            $table->enum('status', ['pending', 'completed', 'cancelled'])->default('completed');
            $table->string('reference')->nullable();
            $table->foreignId('withdrawal_code_id')->nullable()->constrained()->nullOnDelete();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['recharge_agent_id', 'created_at']);
            $table->index(['user_id', 'type']);
        });

        Schema::create('agent_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('recharge_agent_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['recharge_agent_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_ratings');
        Schema::dropIfExists('agent_transfers');
        Schema::dropIfExists('agent_crypto_deposits');
        Schema::dropIfExists('agent_wallets');

        Schema::table('recharge_agents', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['agent_tier_id']);
            $table->dropColumn(['user_id', 'agent_tier_id', 'kyc_verified', 'contract_signed_at', 'rating_avg', 'rating_count']);
        });
    }
};
