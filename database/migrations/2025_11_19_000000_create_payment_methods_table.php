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
        Schema::create('payment_methods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['deposit', 'withdrawal']);
            $table->string('method_key'); // 'crypto', 'cash', 'bank_transfer', 'mobile_money'
            $table->boolean('is_active')->default(true);
            $table->decimal('min_amount', 10, 2)->nullable();
            $table->decimal('max_amount', 10, 2)->nullable();
            $table->decimal('fee_percentage', 5, 2)->nullable();
            $table->decimal('fee_fixed', 10, 2)->nullable();
            // Crypto specific
            $table->string('crypto_address')->nullable();
            $table->string('crypto_network')->nullable();
            // Mobile money specific
            $table->string('mobile_money_provider')->nullable(); // 'MTN', 'Orange', 'Moov'
            // Bank transfer specific
            $table->string('bank_name')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_methods');
    }
};


