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
        Schema::create('ballon_dor_nominations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->onDelete('cascade');
            $table->enum('category', ['player', 'clan', 'team']); // Catégorie de nomination
            $table->string('category_label'); // "Ballon d'Or", "Meilleur Clan", "Meilleure Équipe"
            
            // Référence à l'entité nominée (polymorphique)
            $table->unsignedBigInteger('nominee_id'); // ID du joueur, clan ou équipe
            $table->string('nominee_type'); // 'App\Models\User', 'App\Models\Clan', 'App\Models\Team'
            
            $table->text('description')->nullable(); // Description de la nomination
            $table->text('achievements')->nullable(); // Réalisations/accomplissements
            $table->integer('vote_count')->default(0); // Nombre de votes reçus
            $table->integer('rank')->nullable(); // Classement final
            $table->boolean('is_winner')->default(false); // Gagnant de la catégorie
            $table->timestamps();
            
            $table->index(['season_id', 'category']);
            $table->index(['nominee_id', 'nominee_type']);
            $table->index('vote_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ballon_dor_nominations');
    }
};

