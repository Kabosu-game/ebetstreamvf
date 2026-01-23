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
        Schema::table('promo_codes', function (Blueprint $table) {
            // Renommer 'amount' en 'bonus' pour plus de clarté (optionnel, on peut garder les deux)
            if (!Schema::hasColumn('promo_codes', 'welcome_bonus')) {
                $table->decimal('welcome_bonus', 12, 2)->default(0)->after('amount'); // Bonus crédité à l'inscription
            }
            if (!Schema::hasColumn('promo_codes', 'first_deposit_bonus_percentage')) {
                $table->decimal('first_deposit_bonus_percentage', 5, 2)->default(0)->after('welcome_bonus'); // Pourcentage de bonus sur la première recharge
            }
            if (!Schema::hasColumn('promo_codes', 'premium_days')) {
                $table->integer('premium_days')->default(0)->after('first_deposit_bonus_percentage'); // Nombre de jours d'accès premium
            }
            if (!Schema::hasColumn('promo_codes', 'is_welcome_code')) {
                $table->boolean('is_welcome_code')->default(false)->after('premium_days'); // Indique si c'est un code de bienvenue
            }
            if (!Schema::hasColumn('promo_codes', 'description')) {
                $table->text('description')->nullable()->after('code'); // Description du code
            }
            if (!Schema::hasColumn('promo_codes', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('is_welcome_code'); // Actif ou non
            }
            if (!Schema::hasColumn('promo_codes', 'used_count')) {
                $table->integer('used_count')->default(0)->after('usage_limit'); // Compteur d'utilisation
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('promo_codes', function (Blueprint $table) {
            $table->dropColumn([
                'welcome_bonus',
                'first_deposit_bonus_percentage',
                'premium_days',
                'is_welcome_code',
                'description',
                'is_active',
                'used_count',
            ]);
        });
    }
};


