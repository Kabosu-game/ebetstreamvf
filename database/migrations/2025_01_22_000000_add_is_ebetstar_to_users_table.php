<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (Schema::hasTable('users')) {
            if (!Schema::hasColumn('users', 'is_ebetstar')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->boolean('is_ebetstar')->default(false);
                });
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasTable('users')) {
            if (Schema::hasColumn('users', 'is_ebetstar')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->dropColumn('is_ebetstar');
                });
            }
        }
    }
};

