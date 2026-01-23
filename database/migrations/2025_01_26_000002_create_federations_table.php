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
        Schema::create('federations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Admin/Responsable de la fédération
            $table->string('name'); // Nom de la fédération
            $table->string('slug')->unique(); // URL-friendly name
            $table->text('description')->nullable(); // Description de la fédération
            $table->string('logo')->nullable(); // Logo de la fédération
            $table->string('website')->nullable(); // Site web
            $table->string('email')->nullable(); // Email de contact
            $table->string('phone')->nullable(); // Téléphone de contact
            $table->string('country')->nullable(); // Pays
            $table->string('city')->nullable(); // Ville
            $table->text('address')->nullable(); // Adresse complète
            $table->enum('status', ['pending', 'approved', 'rejected', 'suspended'])->default('pending'); // Statut d'approbation
            $table->text('rejection_reason')->nullable(); // Raison du rejet si applicable
            $table->json('settings')->nullable(); // Paramètres additionnels
            $table->timestamps();
            
            $table->index('status');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('federations');
    }
};

