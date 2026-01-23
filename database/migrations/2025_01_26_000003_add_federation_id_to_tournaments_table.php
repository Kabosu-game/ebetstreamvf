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
        Schema::table('tournaments', function (Blueprint $table) {
            $table->foreignId('federation_id')->nullable()->after('id')->constrained()->onDelete('cascade');
            $table->enum('type', ['individual', 'team'])->default('individual')->after('federation_id'); // Type de compétition
            $table->integer('max_participants')->nullable()->after('type'); // Nombre max de participants
            $table->text('rules')->nullable()->after('max_participants'); // Règles du championnat
            
            $table->index('federation_id');
            $table->index('type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tournaments', function (Blueprint $table) {
            $table->dropForeign(['federation_id']);
            $table->dropColumn(['federation_id', 'type', 'max_participants', 'rules']);
        });
    }
};

