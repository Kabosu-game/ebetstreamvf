<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Add odds to championship_matches
        Schema::table('championship_matches', function (Blueprint $table) {
            $table->decimal('player1_odds', 5, 2)->default(2.00)->after('player2_id');
            $table->decimal('draw_odds', 5, 2)->nullable()->after('player1_odds');
            $table->decimal('player2_odds', 5, 2)->default(2.00)->after('draw_odds');
        });

        // Add championship_match_id to bets table
        Schema::table('bets', function (Blueprint $table) {
            $table->foreignId('championship_match_id')->nullable()->after('challenge_id')->constrained('championship_matches')->onDelete('cascade');
        });

        // Update bet_type enum to include championship match types
        DB::statement("ALTER TABLE bets MODIFY COLUMN bet_type ENUM('team1_win', 'draw', 'team2_win', 'creator_win', 'opponent_win', 'player1_win', 'player2_win') NOT NULL");

        // Add index for championship_match_id
        Schema::table('bets', function (Blueprint $table) {
            $table->index('championship_match_id');
            $table->index(['championship_match_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->dropIndex(['championship_match_id', 'status']);
            $table->dropIndex(['championship_match_id']);
            $table->dropForeign(['championship_match_id']);
            $table->dropColumn('championship_match_id');
        });

        // Revert bet_type enum
        DB::statement("ALTER TABLE bets MODIFY COLUMN bet_type ENUM('team1_win', 'draw', 'team2_win', 'creator_win', 'opponent_win') NOT NULL");

        Schema::table('championship_matches', function (Blueprint $table) {
            $table->dropColumn(['player1_odds', 'draw_odds', 'player2_odds']);
        });
    }
};

