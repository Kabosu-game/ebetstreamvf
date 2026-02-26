<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('challenges', function (Blueprint $table) {
            if (!Schema::hasColumn('challenges', 'type')) {
                $table->enum('type', ['user', 'clan'])->default('user')->after('id');
            }
            if (!Schema::hasColumn('challenges', 'creator_clan_id')) {
                $table->foreignId('creator_clan_id')->nullable()->after('creator_id')->constrained('clans')->onDelete('cascade');
            }
            if (!Schema::hasColumn('challenges', 'opponent_clan_id')) {
                $table->foreignId('opponent_clan_id')->nullable()->after('opponent_id')->constrained('clans')->onDelete('set null');
            }
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

