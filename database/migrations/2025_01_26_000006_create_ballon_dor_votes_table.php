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
        Schema::create('ballon_dor_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->onDelete('cascade');
            $table->foreignId('nomination_id')->constrained('ballon_dor_nominations')->onDelete('cascade');
            
            // Qui vote (polymorphique - peut être un user, une fédération, etc.)
            $table->unsignedBigInteger('voter_id');
            $table->string('voter_type'); // 'App\Models\User', 'App\Models\Federation'
            
            $table->enum('category', ['player', 'clan', 'team']); // Catégorie votée
            $table->integer('points')->default(1); // Points attribués (pour système de classement)
            $table->text('comment')->nullable(); // Commentaire optionnel
            $table->timestamps();
            
            // Un votant ne peut voter qu'une fois par catégorie par saison
            $table->unique(['season_id', 'voter_id', 'voter_type', 'category'], 'unique_vote_per_category');
            $table->index(['season_id', 'nomination_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ballon_dor_votes');
    }
};

