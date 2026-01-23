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
            // Add type column if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'type')) {
                $table->enum('type', ['organizer', 'referee', 'ambassador'])->nullable()->after('user_id');
            }
            
            // Add full_name if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'full_name')) {
                $table->string('full_name')->nullable()->after('type');
            }
            
            // Add birth_date if it doesn't exist (different from date_of_birth)
            if (!Schema::hasColumn('certification_requests', 'birth_date')) {
                $table->date('birth_date')->nullable()->after('full_name');
            }
            
            // Add country if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'country')) {
                $table->string('country')->nullable()->after('birth_date');
            }
            
            // Add city if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'city')) {
                $table->string('city')->nullable()->after('country');
            }
            
            // Add phone if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'phone')) {
                $table->string('phone')->nullable()->after('city');
            }
            
            // Add professional_email if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'professional_email')) {
                $table->string('professional_email')->nullable()->after('phone');
            }
            
            // Add username if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'username')) {
                $table->string('username')->nullable()->after('professional_email');
            }
            
            // Add experience if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'experience')) {
                $table->text('experience')->nullable()->after('username');
            }
            
            // Add availability if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'availability')) {
                $table->text('availability')->nullable()->after('experience');
            }
            
            // Add technical_skills if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'technical_skills')) {
                $table->text('technical_skills')->nullable()->after('availability');
            }
            
            // Add id_card_front if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'id_card_front')) {
                $table->string('id_card_front')->nullable()->after('technical_skills');
            }
            
            // Add id_card_back if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'id_card_back')) {
                $table->string('id_card_back')->nullable()->after('id_card_front');
            }
            
            // Add selfie if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'selfie')) {
                $table->string('selfie')->nullable()->after('id_card_back');
            }
            
            // Add event_proof if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'event_proof')) {
                $table->text('event_proof')->nullable()->after('selfie');
            }
            
            // Add tournament_structure if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'tournament_structure')) {
                $table->string('tournament_structure')->nullable()->after('event_proof');
            }
            
            // Add professional_contacts if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'professional_contacts')) {
                $table->text('professional_contacts')->nullable()->after('tournament_structure');
            }
            
            // Add mini_cv if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'mini_cv')) {
                $table->text('mini_cv')->nullable()->after('professional_contacts');
            }
            
            // Add presentation_video if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'presentation_video')) {
                $table->string('presentation_video')->nullable()->after('mini_cv');
            }
            
            // Add community_proof if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'community_proof')) {
                $table->text('community_proof')->nullable()->after('presentation_video');
            }
            
            // Add social_media_links if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'social_media_links')) {
                $table->text('social_media_links')->nullable()->after('community_proof');
            }
            
            // Add audience_stats if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'audience_stats')) {
                $table->text('audience_stats')->nullable()->after('social_media_links');
            }
            
            // Add previous_media if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'previous_media')) {
                $table->text('previous_media')->nullable()->after('audience_stats');
            }
            
            // Add submitted_at if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'submitted_at')) {
                $table->timestamp('submitted_at')->nullable()->after('previous_media');
            }
            
            // Add test_completed_at if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'test_completed_at')) {
                $table->timestamp('test_completed_at')->nullable()->after('submitted_at');
            }
            
            // Add interview_completed_at if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'interview_completed_at')) {
                $table->timestamp('interview_completed_at')->nullable()->after('test_completed_at');
            }
            
            // Add approved_at if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('interview_completed_at');
            }
            
            // Add rejected_at if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'rejected_at')) {
                $table->timestamp('rejected_at')->nullable()->after('approved_at');
            }
            
            // Add notes if it doesn't exist
            if (!Schema::hasColumn('certification_requests', 'notes')) {
                $table->text('notes')->nullable()->after('rejected_at');
            }
            
            // Update status enum if needed
            if (Schema::hasColumn('certification_requests', 'status')) {
                // Check current enum values
                $column = DB::select("SHOW COLUMNS FROM certification_requests WHERE Field = 'status'");
                if (!empty($column) && strpos($column[0]->Type, 'under_review') === false) {
                    // Update enum to include new values
                    DB::statement("ALTER TABLE certification_requests MODIFY COLUMN status ENUM('pending', 'under_review', 'test_pending', 'interview_pending', 'approved', 'rejected') DEFAULT 'pending'");
                }
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Don't drop columns in down() to preserve data
        // If needed, columns can be dropped manually
    }
};

