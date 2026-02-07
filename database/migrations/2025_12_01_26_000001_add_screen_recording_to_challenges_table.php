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
        Schema::table('challenges', function (Blueprint $table) {
            // URLs pour les streams d'écran des participants
            $table->string('creator_screen_stream_url')->nullable()->after('opponent_score');
            $table->string('opponent_screen_stream_url')->nullable()->after('creator_screen_stream_url');
            // Indicateurs si les streams sont actifs
            $table->boolean('creator_screen_recording')->default(false)->after('opponent_screen_stream_url');
            $table->boolean('opponent_screen_recording')->default(false)->after('creator_screen_recording');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('challenges', function (Blueprint $table) {
            $table->dropColumn([
                'creator_screen_stream_url',
                'opponent_screen_stream_url',
                'creator_screen_recording',
                'opponent_screen_recording',
            ]);
        });
    }
};

