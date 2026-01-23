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
        Schema::table('agent_requests', function (Blueprint $table) {
            $table->string('phone')->nullable(); // Téléphone secondaire
            $table->date('birth_date')->nullable(); // Date de naissance
            $table->string('city')->nullable(); // Ville
            $table->string('occupation')->nullable(); // Profession
            $table->string('experience')->nullable(); // Niveau d'expérience
            $table->text('skills')->nullable(); // Compétences et qualifications
            $table->string('availability')->nullable(); // Type de disponibilité
            $table->string('working_hours')->nullable(); // Heures de travail préférées
            $table->text('motivation')->nullable(); // Motivation pour devenir agent
            $table->string('has_id_card')->nullable(); // A une pièce d'identité
            $table->string('has_business_license')->nullable(); // A un enregistrement d'entreprise
            $table->boolean('agree_terms')->default(false); // Acceptation des termes
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('agent_requests', function (Blueprint $table) {
            $table->dropColumn([
                'phone',
                'birth_date',
                'city',
                'occupation',
                'experience',
                'skills',
                'availability',
                'working_hours',
                'motivation',
                'has_id_card',
                'has_business_license',
                'agree_terms'
            ]);
        });
    }
};
