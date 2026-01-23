<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('championships', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // EBETSTREAM Championship
            $table->string('game'); // COD Mobile, Free Fire, PUBG Mobile, etc.
            $table->enum('division', ['1', '2', '3']); // Division 1, 2, 3
            $table->text('description')->nullable();
            $table->text('rules')->nullable();
            
            // Prix et inscription
            $table->decimal('registration_fee', 10, 2); // Prix d'inscription
            $table->decimal('total_prize_pool', 10, 2)->nullable(); // Pool de prix total
            $table->json('prize_distribution')->nullable(); // Distribution des prix (1er, 2ème, etc.)
            
            // Dates
            $table->date('registration_start_date');
            $table->date('registration_end_date');
            $table->date('start_date');
            $table->date('end_date')->nullable();
            
            // Limites
            $table->integer('max_participants')->default(32);
            $table->integer('min_participants')->default(8);
            
            // Statut
            $table->enum('status', ['draft', 'registration_open', 'registration_closed', 'validated', 'started', 'finished', 'cancelled'])->default('draft');
            
            // Images
            $table->string('banner_image')->nullable();
            $table->string('thumbnail_image')->nullable();
            
            // Informations additionnelles
            $table->boolean('is_active')->default(true);
            $table->integer('current_round')->default(0);
            $table->text('admin_notes')->nullable();
            
            $table->timestamps();
        });

        // Table pour les inscriptions
        Schema::create('championship_registrations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('championship_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('team_id')->nullable()->constrained()->onDelete('set null'); // Si c'est un championnat par équipe
            
            // Informations du formulaire d'inscription
            $table->string('player_name'); // Nom du joueur
            $table->string('player_username'); // Username IG
            $table->string('player_id')->nullable(); // ID IG
            $table->string('player_rank')->nullable(); // Rang IG
            $table->string('contact_phone')->nullable();
            $table->string('contact_email')->nullable();
            $table->text('additional_info')->nullable(); // Informations additionnelles
            
            // Statut et paiement
            $table->enum('status', ['pending', 'paid', 'validated', 'rejected', 'cancelled'])->default('pending');
            $table->foreignId('transaction_id')->nullable()->constrained('transactions')->onDelete('set null');
            $table->decimal('fee_paid', 10, 2)->default(0);
            
            // Classement dans le championnat
            $table->integer('current_position')->nullable(); // Position actuelle
            $table->integer('matches_won')->default(0);
            $table->integer('matches_lost')->default(0);
            $table->integer('matches_drawn')->default(0);
            $table->integer('points')->default(0); // Points au classement
            
            $table->timestamp('registered_at');
            $table->timestamp('validated_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            
            // Unique constraint: un utilisateur ne peut s'inscrire qu'une fois par championnat
            $table->unique(['championship_id', 'user_id']);
        });

        // Table pour les matchs du championnat
        Schema::create('championship_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('championship_id')->constrained()->onDelete('cascade');
            $table->integer('round_number'); // Numéro de round (1, 2, 3, etc.)
            
            // Participants
            $table->foreignId('player1_id')->constrained('championship_registrations')->onDelete('cascade');
            $table->foreignId('player2_id')->constrained('championship_registrations')->onDelete('cascade');
            
            // Résultats
            $table->integer('player1_score')->nullable();
            $table->integer('player2_score')->nullable();
            $table->foreignId('winner_id')->nullable()->constrained('championship_registrations')->onDelete('set null');
            
            // Statut
            $table->enum('status', ['scheduled', 'ongoing', 'completed', 'cancelled'])->default('scheduled');
            
            // Dates
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            
            // Informations additionnelles
            $table->text('match_details')->nullable(); // Détails du match (JSON)
            $table->text('admin_notes')->nullable();
            
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('championship_matches');
        Schema::dropIfExists('championship_registrations');
        Schema::dropIfExists('championships');
    }
};

