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
            // Champs pour le streaming live
            $table->boolean('is_live')->default(false)->after('opponent_screen_recording');
            $table->string('stream_key')->nullable()->after('is_live');
            $table->string('rtmp_url')->nullable()->after('stream_key');
            $table->string('stream_url')->nullable()->after('rtmp_url'); // URL publique pour voir le stream
            $table->timestamp('live_started_at')->nullable()->after('stream_url');
            $table->timestamp('live_ended_at')->nullable()->after('live_started_at');
            $table->integer('viewer_count')->default(0)->after('live_ended_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('challenges', function (Blueprint $table) {
            $table->dropColumn([
                'is_live',
                'stream_key',
                'rtmp_url',
                'stream_url',
                'live_started_at',
                'live_ended_at',
                'viewer_count',
            ]);
        });
    }
};

