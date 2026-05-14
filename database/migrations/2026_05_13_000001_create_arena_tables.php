<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('arena_player_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->enum('player_class', ['attacker', 'defender', 'support', 'tactical'])->default('attacker');
            $table->enum('rank', ['bronze', 'silver', 'gold', 'elite', 'champion'])->default('bronze');
            $table->enum('league_tier', ['amateur', 'semi_pro', 'pro', 'champion'])->default('amateur');
            $table->unsignedInteger('level')->default(1);
            $table->unsignedInteger('mmr')->default(1000);
            $table->unsignedInteger('points')->default(0);
            $table->unsignedInteger('matches_played')->default(0);
            $table->unsignedInteger('matches_won')->default(0);
            $table->unsignedInteger('matches_lost')->default(0);
            $table->timestamps();
        });

        Schema::create('arena_matches', function (Blueprint $table) {
            $table->id();
            $table->string('team1_name')->default('Team Alpha');
            $table->string('team2_name')->default('Team Omega');
            $table->unsignedSmallInteger('team1_score')->default(0);
            $table->unsignedSmallInteger('team2_score')->default(0);
            $table->decimal('team1_odds', 6, 2)->default(1.90);
            $table->decimal('team2_odds', 6, 2)->default(1.90);
            $table->enum('mode', ['quick_match', 'ranked', 'tournament', 'private_match'])->default('quick_match');
            $table->enum('league_tier', ['amateur', 'semi_pro', 'pro', 'champion'])->default('amateur');
            $table->enum('status', ['waiting', 'scheduled', 'live', 'completed', 'cancelled'])->default('scheduled');
            $table->enum('winner_team', ['team1', 'team2', 'draw'])->nullable();
            $table->unsignedTinyInteger('max_players_per_team')->default(5);
            $table->json('match_state')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'scheduled_at']);
            $table->index('mode');
        });

        Schema::create('arena_match_players', function (Blueprint $table) {
            $table->id();
            $table->foreignId('arena_match_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('team', ['team1', 'team2']);
            $table->enum('player_class', ['attacker', 'defender', 'support', 'tactical'])->default('attacker');
            $table->boolean('is_mvp')->default(false);
            $table->timestamps();

            $table->unique(['arena_match_id', 'user_id']);
        });

        Schema::table('bets', function (Blueprint $table) {
            $table->foreignId('arena_match_id')->nullable()->after('championship_match_id')
                ->constrained('arena_matches')->cascadeOnDelete();
            $table->index('arena_match_id');
        });
    }

    public function down(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->dropForeign(['arena_match_id']);
            $table->dropColumn('arena_match_id');
        });

        Schema::dropIfExists('arena_match_players');
        Schema::dropIfExists('arena_matches');
        Schema::dropIfExists('arena_player_profiles');
    }
};
