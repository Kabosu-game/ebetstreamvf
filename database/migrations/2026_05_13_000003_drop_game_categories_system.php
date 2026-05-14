<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('games', 'game_category_id')) {
            Schema::table('games', function (Blueprint $table) {
                $table->dropForeign(['game_category_id']);
                $table->dropColumn('game_category_id');
            });
        }

        Schema::dropIfExists('game_categories');
    }

    public function down(): void
    {
        Schema::create('game_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('icon')->nullable();
            $table->integer('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::table('games', function (Blueprint $table) {
            $table->foreignId('game_category_id')->nullable()->constrained('game_categories')->onDelete('cascade');
        });
    }
};
