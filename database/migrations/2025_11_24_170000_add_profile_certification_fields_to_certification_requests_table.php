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
        Schema::table('certification_requests', function (Blueprint $table) {
            // Add fields for profile certification (if they don't exist)
            if (!Schema::hasColumn('certification_requests', 'date_of_birth')) {
                $table->date('date_of_birth')->nullable()->after('birth_date');
            }
            if (!Schema::hasColumn('certification_requests', 'id_type')) {
                $table->enum('id_type', ['passport', 'national_id', 'driving_license', 'residence_permit'])->nullable()->after('date_of_birth');
            }
            if (!Schema::hasColumn('certification_requests', 'id_number')) {
                $table->string('id_number', 100)->nullable()->after('id_type');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('certification_requests', function (Blueprint $table) {
            if (Schema::hasColumn('certification_requests', 'id_number')) {
                $table->dropColumn('id_number');
            }
            if (Schema::hasColumn('certification_requests', 'id_type')) {
                $table->dropColumn('id_type');
            }
            if (Schema::hasColumn('certification_requests', 'date_of_birth')) {
                $table->dropColumn('date_of_birth');
            }
        });
    }
};

