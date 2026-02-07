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
        Schema::table('events', function (Blueprint $table) {
            if (!Schema::hasColumn('events', 'image')) {
                $table->string('image')->nullable()->after('location');
            }
            if (!Schema::hasColumn('events', 'status')) {
                $table->enum('status', ['draft', 'published', 'cancelled'])->default('published')->after('image');
            }
            if (!Schema::hasColumn('events', 'type')) {
                $table->string('type')->nullable()->after('status');
            }
            if (!Schema::hasColumn('events', 'max_participants')) {
                $table->integer('max_participants')->nullable()->after('type');
            }
            if (!Schema::hasColumn('events', 'registration_deadline')) {
                $table->timestamp('registration_deadline')->nullable()->after('max_participants');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table) {
            $table->dropColumn([
                'image',
                'status',
                'type',
                'max_participants',
                'registration_deadline'
            ]);
        });
    }
};
