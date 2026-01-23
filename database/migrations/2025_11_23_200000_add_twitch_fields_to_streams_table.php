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
        Schema::table('streams', function (Blueprint $table) {
            $table->boolean('use_twitch')->default(false)->after('is_live');
            $table->string('twitch_username')->nullable()->after('use_twitch');
            $table->string('twitch_stream_key')->nullable()->after('twitch_username');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('streams', function (Blueprint $table) {
            $table->dropColumn(['use_twitch', 'twitch_username', 'twitch_stream_key']);
        });
    }
};

