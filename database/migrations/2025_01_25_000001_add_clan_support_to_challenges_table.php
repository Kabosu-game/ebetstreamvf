<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('challenges', function (Blueprint $table) {
            // Type de défi: 'user' (défi entre utilisateurs) ou 'clan' (défi entre clans)
            $table->enum('type', ['user', 'clan'])->default('user')->after('id');
            
            // IDs des clans (si type = 'clan')
            $table->foreignId('creator_clan_id')->nullable()->after('creator_id')->constrained('clans')->onDelete('cascade');
            $table->foreignId('opponent_clan_id')->nullable()->after('opponent_id')->constrained('clans')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('challenges', function (Blueprint $table) {
            $table->dropForeign(['creator_clan_id']);
            $table->dropForeign(['opponent_clan_id']);
            $table->dropColumn(['type', 'creator_clan_id', 'opponent_clan_id']);
        });
    }
};

