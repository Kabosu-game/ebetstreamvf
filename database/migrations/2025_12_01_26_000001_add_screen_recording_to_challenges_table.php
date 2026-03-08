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
            if (!Schema::hasColumn('challenges', 'creator_screen_stream_url')) {
                $table->string('creator_screen_stream_url')->nullable()->after('opponent_score');
            }
            if (!Schema::hasColumn('challenges', 'opponent_screen_stream_url')) {
                $table->string('opponent_screen_stream_url')->nullable()->after('creator_screen_stream_url');
            }
            if (!Schema::hasColumn('challenges', 'creator_screen_recording')) {
                $table->boolean('creator_screen_recording')->default(false)->after('opponent_screen_stream_url');
            }
            if (!Schema::hasColumn('challenges', 'opponent_screen_recording')) {
                $table->boolean('opponent_screen_recording')->default(false)->after('creator_screen_recording');
            }
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

