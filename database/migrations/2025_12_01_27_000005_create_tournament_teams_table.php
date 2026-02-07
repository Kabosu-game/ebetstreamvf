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
        // Table pour les équipes participantes aux tournois
        Schema::create('tournament_teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tournament_id')->constrained()->onDelete('cascade');
            $table->foreignId('team_id')->constrained()->onDelete('cascade');
            $table->foreignId('registered_by')->constrained('users')->onDelete('cascade'); // Utilisateur qui a inscrit l'équipe
            $table->enum('status', ['pending', 'confirmed', 'rejected'])->default('confirmed');
            $table->timestamp('registered_at')->useCurrent();
            $table->timestamps();

            // Une équipe ne peut s'inscrire qu'une fois par tournoi
            $table->unique(['tournament_id', 'team_id']);
            
            $table->index('tournament_id');
            $table->index('team_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tournament_teams');
    }
};


