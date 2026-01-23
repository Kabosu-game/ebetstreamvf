<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Modify the enum type to include 'championship_registration'
        // MySQL requires altering the column definition
        DB::statement("ALTER TABLE transactions MODIFY COLUMN type ENUM('deposit', 'withdraw', 'bet', 'win', 'refund', 'championship_registration') NOT NULL");
    }

    public function down(): void
    {
        // Revert to original enum values
        DB::statement("ALTER TABLE transactions MODIFY COLUMN type ENUM('deposit', 'withdraw', 'bet', 'win', 'refund') NOT NULL");
    }
};

