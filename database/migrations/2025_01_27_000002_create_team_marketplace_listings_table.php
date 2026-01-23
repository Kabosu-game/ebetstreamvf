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
        Schema::create('team_marketplace_listings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->onDelete('cascade');
            $table->foreignId('seller_id')->constrained('users')->onDelete('cascade');
            $table->enum('listing_type', ['sale', 'loan']); // vente ou prêt
            $table->decimal('price', 15, 2)->nullable(); // Prix de vente (si sale)
            $table->decimal('loan_fee', 15, 2)->nullable(); // Frais de prêt (si loan)
            $table->integer('loan_duration_days')->nullable(); // Durée du prêt en jours (si loan)
            $table->text('conditions')->nullable(); // Conditions spécifiques (ex: minimum level, etc.)
            $table->enum('status', ['active', 'pending', 'sold', 'loaned', 'cancelled'])->default('active');
            $table->foreignId('buyer_id')->nullable()->constrained('users')->onDelete('set null');
            $table->timestamp('sold_at')->nullable();
            $table->timestamp('loan_start_date')->nullable();
            $table->timestamp('loan_end_date')->nullable();
            $table->timestamps();
            
            $table->index('team_id');
            $table->index('seller_id');
            $table->index('status');
            $table->index('listing_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_marketplace_listings');
    }
};

