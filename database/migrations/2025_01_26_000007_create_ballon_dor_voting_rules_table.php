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
        Schema::create('ballon_dor_voting_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->onDelete('cascade');
            $table->enum('category', ['player', 'clan', 'team']);
            
            // Qui peut voter
            $table->boolean('community_can_vote')->default(false); // Communauté
            $table->boolean('players_can_vote')->default(false); // Joueurs participants
            $table->boolean('federations_can_vote')->default(false); // Fédérations
            
            // Règles additionnelles
            $table->integer('min_participations')->nullable(); // Nombre minimum de participations requis pour voter
            $table->integer('max_votes_per_category')->default(1); // Nombre max de votes par catégorie
            $table->json('additional_rules')->nullable(); // Règles additionnelles en JSON
            
            $table->timestamps();
            
            $table->unique(['season_id', 'category']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ballon_dor_voting_rules');
    }
};

