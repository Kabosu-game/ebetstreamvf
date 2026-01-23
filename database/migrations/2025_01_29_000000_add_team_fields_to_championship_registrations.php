<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('championship_registrations', function (Blueprint $table) {
            // Nouveaux champs pour le formulaire d'inscription
            $table->string('full_name')->nullable()->after('user_id'); // Nom complet
            $table->string('team_name')->nullable()->after('team_id'); // Nom de l'équipe
            $table->string('team_logo')->nullable()->after('team_name'); // Logo de l'équipe
            $table->text('players_list')->nullable()->after('player_rank'); // Liste des joueurs (JSON)
            $table->boolean('accept_terms')->default(false)->after('additional_info'); // Acceptation des règles
        });
    }

    public function down(): void
    {
        Schema::table('championship_registrations', function (Blueprint $table) {
            $table->dropColumn(['full_name', 'team_name', 'team_logo', 'players_list', 'accept_terms']);
        });
    }
};

