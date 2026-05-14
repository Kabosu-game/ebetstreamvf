<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Donations directes (Source A : 85/15) ───────────────────────────
        Schema::create('stream_donations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_id')->constrained('streams')->onDelete('cascade');
            $table->foreignId('donor_user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('streamer_user_id')->constrained('users')->onDelete('cascade');
            $table->decimal('amount', 14, 2);
            $table->decimal('streamer_amount', 14, 2); // 85%
            $table->decimal('platform_amount', 14, 2); // 15%
            $table->decimal('streamer_percent', 5, 2)->default(85);
            $table->string('message', 500)->nullable();
            $table->string('status')->default('completed'); // completed, refunded
            $table->timestamps();

            $table->index(['stream_id', 'created_at']);
            $table->index(['streamer_user_id', 'created_at']);
        });

        // ── Support Prediction (Source B : commission 40/60) ─────────────────
        Schema::create('stream_predictions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stream_id')->constrained('streams')->onDelete('cascade');
            $table->foreignId('predictor_user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('streamer_user_id')->constrained('users')->onDelete('cascade');
            $table->decimal('credits_amount', 14, 2);       // Mise du spectateur
            $table->decimal('platform_commission', 14, 2);  // commission totale prélevée
            $table->decimal('streamer_share', 14, 2);       // 40% de la commission
            $table->decimal('platform_share', 14, 2);       // 60% de la commission
            $table->decimal('commission_rate', 5, 2)->default(15); // % commission sur la mise
            $table->decimal('streamer_percent', 5, 2)->default(40); // % commission au streamer
            $table->string('prediction_type')->default('support'); // support, outcome
            $table->string('status')->default('active'); // active, won, lost, refunded
            $table->timestamps();

            $table->index(['stream_id', 'created_at']);
            $table->index(['streamer_user_id', 'created_at']);
        });

        // ── Matchs sponsorisés (Source C : 60/20/20) ─────────────────────────
        Schema::create('sponsored_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('championship_id')->nullable()->constrained('championships')->onDelete('set null');
            $table->foreignId('organizer_user_id')->constrained('users')->onDelete('cascade');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('game')->nullable();
            $table->decimal('prize_pool_total', 14, 2);
            $table->decimal('players_prize', 14, 2);      // 60%
            $table->decimal('organizer_prize', 14, 2);    // 20%
            $table->decimal('platform_prize', 14, 2);     // 20%
            $table->string('status')->default('open'); // open, ongoing, completed, cancelled
            $table->boolean('distributed')->default(false);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('sponsored_match_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sponsored_match_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('placement')->nullable(); // 1st, 2nd, 3rd...
            $table->decimal('prize_received', 14, 2)->default(0);
            $table->timestamps();

            $table->unique(['sponsored_match_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sponsored_match_participants');
        Schema::dropIfExists('sponsored_matches');
        Schema::dropIfExists('stream_predictions');
        Schema::dropIfExists('stream_donations');
    }
};
