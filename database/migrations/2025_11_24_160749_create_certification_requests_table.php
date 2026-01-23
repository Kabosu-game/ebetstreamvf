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
        Schema::create('certification_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->onDelete('set null');
            $table->enum('type', ['organizer', 'referee', 'ambassador']); // Type de certification
            $table->enum('status', ['pending', 'under_review', 'test_pending', 'interview_pending', 'approved', 'rejected'])->default('pending');
            
            // Informations personnelles
            $table->string('full_name');
            $table->date('birth_date')->nullable();
            $table->string('country')->nullable();
            $table->string('city')->nullable();
            $table->string('phone');
            $table->string('professional_email');
            $table->string('username'); // Pseudo Ebetstream
            
            // Informations professionnelles
            $table->text('experience')->nullable(); // Expérience dans l'e-sport
            $table->text('availability')->nullable(); // Disponibilités
            $table->text('technical_skills')->nullable(); // Compétences techniques
            
            // Documents
            $table->string('id_card_front')->nullable(); // Carte d'identité recto
            $table->string('id_card_back')->nullable(); // Carte d'identité verso
            $table->string('selfie')->nullable(); // Selfie pour vérification
            
            // Documents spécifiques selon le type
            $table->text('specific_documents')->nullable(); // JSON pour documents spécifiques
            
            // Pour Organisateurs
            $table->text('event_proof')->nullable(); // Preuve d'événement organisé
            $table->string('tournament_structure')->nullable(); // Document structure tournoi
            $table->text('professional_contacts')->nullable(); // Coordonnées professionnelles
            
            // Pour Arbitres
            $table->text('mini_cv')->nullable(); // Mini CV
            $table->string('presentation_video')->nullable(); // Vidéo de présentation
            $table->text('community_proof')->nullable(); // Preuve activité communautés
            
            // Pour Ambassadeurs
            $table->text('social_media_links')->nullable(); // Liens réseaux sociaux
            $table->text('audience_stats')->nullable(); // Statistiques audience
            $table->text('previous_media')->nullable(); // Médias précédents
            
            // Processus de validation
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('test_completed_at')->nullable();
            $table->timestamp('interview_completed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->onDelete('set null');
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable(); // Notes internes
            
            $table->timestamps();
            
            $table->index(['user_id', 'type']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('certification_requests');
    }
};
