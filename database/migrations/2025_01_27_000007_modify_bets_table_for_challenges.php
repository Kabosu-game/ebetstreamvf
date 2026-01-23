<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            // Rendre game_match_id nullable car maintenant on peut parier sur des défis
            $table->foreignId('game_match_id')->nullable()->change();
            
            // Ajouter challenge_id
            $table->foreignId('challenge_id')->nullable()->after('game_match_id')->constrained('challenges')->onDelete('cascade');
            
            // Modifier bet_type pour supporter les défis (creator_win, opponent_win)
            // On garde les anciens types pour les matches (team1_win, draw, team2_win)
            // Mais on ajoute les nouveaux types pour les défis
            // Note: On ne peut pas modifier un enum directement, donc on va le faire en plusieurs étapes
        });
        
        // Pour modifier l'enum, on doit le faire via une requête SQL directe
        DB::statement("ALTER TABLE bets MODIFY COLUMN bet_type ENUM('team1_win', 'draw', 'team2_win', 'creator_win', 'opponent_win') NOT NULL");
        
        // Ajouter des index pour améliorer les performances
        Schema::table('bets', function (Blueprint $table) {
            $table->index('challenge_id');
            $table->index(['challenge_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bets', function (Blueprint $table) {
            $table->dropIndex(['challenge_id', 'status']);
            $table->dropIndex(['challenge_id']);
            $table->dropForeign(['challenge_id']);
            $table->dropColumn('challenge_id');
            
            // Remettre game_match_id en NOT NULL
            $table->foreignId('game_match_id')->nullable(false)->change();
        });
        
        // Remettre l'enum original
        DB::statement("ALTER TABLE bets MODIFY COLUMN bet_type ENUM('team1_win', 'draw', 'team2_win') NOT NULL");
    }
};

