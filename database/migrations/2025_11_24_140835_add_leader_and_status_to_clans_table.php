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
        Schema::table('clans', function (Blueprint $table) {
            $table->foreignId('leader_id')->nullable()->after('description')->constrained('users')->onDelete('set null');
            $table->enum('status', ['active', 'inactive'])->default('active')->after('leader_id');
            $table->integer('member_count')->default(0)->after('status');
            $table->integer('max_members')->default(50)->after('member_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('clans', function (Blueprint $table) {
            $table->dropForeign(['leader_id']);
            $table->dropColumn(['leader_id', 'status', 'member_count', 'max_members']);
        });
    }
};
