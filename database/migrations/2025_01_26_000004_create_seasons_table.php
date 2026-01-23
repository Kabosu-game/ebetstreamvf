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
        Schema::create('seasons', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // Ex: "Saison 2024", "Saison 2025"
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->date('start_date'); // Date de début de saison
            $table->date('end_date'); // Date de fin de saison
            $table->date('voting_start_date')->nullable(); // Date de début des votes
            $table->date('voting_end_date')->nullable(); // Date de fin des votes
            $table->enum('status', ['upcoming', 'active', 'voting', 'completed'])->default('upcoming');
            $table->boolean('is_current')->default(false); // Saison actuelle
            $table->timestamps();
            
            $table->index('status');
            $table->index('is_current');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('seasons');
    }
};

