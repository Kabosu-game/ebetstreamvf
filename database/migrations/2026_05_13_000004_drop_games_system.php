<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('bets', 'game_match_id')) {
            Schema::table('bets', function (Blueprint $table) {
                $table->dropForeign(['game_match_id']);
                $table->dropColumn('game_match_id');
            });
        }

        Schema::dropIfExists('game_matches');
        Schema::dropIfExists('games');
    }

    public function down(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->string('image')->nullable();
            $table->integer('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('game_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained('games')->onDelete('cascade');
            $table->string('team1_name');
            $table->string('team2_name');
            $table->text('description')->nullable();
            $table->dateTime('match_date')->nullable();
            $table->string('status')->default('upcoming');
            $table->decimal('team1_odds', 8, 2)->default(1.50);
            $table->decimal('draw_odds', 8, 2)->nullable();
            $table->decimal('team2_odds', 8, 2)->default(1.50);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('bets', function (Blueprint $table) {
            $table->foreignId('game_match_id')->nullable()->constrained('game_matches')->onDelete('cascade');
        });
    }
};
