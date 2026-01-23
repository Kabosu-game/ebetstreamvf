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
        Schema::create('withdrawal_codes', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // Code unique de retrait
            $table->decimal('amount', 10, 2); // Montant du retrait
            $table->foreignId('user_id')->constrained()->onDelete('cascade'); // Utilisateur qui demande le retrait
            $table->foreignId('recharge_agent_id')->nullable()->constrained()->onDelete('set null'); // Agent de recharge sélectionné
            $table->enum('status', ['pending', 'completed', 'cancelled', 'expired'])->default('pending'); // Statut du code
            $table->timestamp('expires_at')->nullable(); // Date d'expiration
            $table->timestamp('completed_at')->nullable(); // Date de complétion
            $table->text('notes')->nullable(); // Notes additionnelles
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('withdrawal_codes');
    }
};
