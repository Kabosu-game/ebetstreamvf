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
        Schema::create('challenge_stop_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenge_id')->constrained()->onDelete('cascade');
            $table->foreignId('initiator_id')->constrained('users')->onDelete('cascade'); // Qui a initié la demande
            $table->foreignId('confirmer_id')->nullable()->constrained('users')->onDelete('cascade'); // Qui a confirmé
            $table->enum('status', ['pending', 'confirmed', 'approved', 'rejected'])->default('pending');
            $table->text('reason')->nullable(); // Raison de l'arrêt
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamps();
            
            $table->unique(['challenge_id']); // Une seule demande par challenge
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('challenge_stop_requests');
    }
};
