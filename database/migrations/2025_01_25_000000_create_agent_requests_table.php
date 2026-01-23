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
        Schema::create('agent_requests', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('whatsapp'); // Numéro WhatsApp
            $table->string('email')->nullable();
            $table->text('message')->nullable(); // Message optionnel
            $table->string('status')->default('pending'); // pending, approved, rejected
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null'); // Optionnel : si l'utilisateur est connecté
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('agent_requests');
    }
};





