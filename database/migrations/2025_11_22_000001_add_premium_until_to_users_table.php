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
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'premium_until')) {
                $table->timestamp('premium_until')->nullable()->after('role'); // Date d'expiration de l'accès premium
            }
            if (!Schema::hasColumn('users', 'used_welcome_code')) {
                $table->string('used_welcome_code')->nullable()->after('promo_code'); // Code de bienvenue utilisé
            }
            if (!Schema::hasColumn('users', 'first_deposit_bonus_applied')) {
                $table->boolean('first_deposit_bonus_applied')->default(false)->after('premium_until'); // Indique si le bonus première recharge a été appliqué
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['premium_until', 'used_welcome_code', 'first_deposit_bonus_applied']);
        });
    }
};


