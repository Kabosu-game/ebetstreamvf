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
        // Indexes for federations table
        try {
            Schema::table('federations', function (Blueprint $table) {
                $table->index('status');
                $table->index('country');
                $table->index('user_id');
            });
        } catch (\Exception $e) {
            // Indexes may already exist
        }

        // Indexes for tournaments table
        try {
            Schema::table('tournaments', function (Blueprint $table) {
                $table->index('federation_id');
                $table->index('status');
                $table->index('type');
                $table->index('division');
                $table->index('start_at');
            });
        } catch (\Exception $e) {
            // Indexes may already exist
        }

        // Indexes for tournament_teams table
        try {
            Schema::table('tournament_teams', function (Blueprint $table) {
                $table->index('tournament_id');
                $table->index('team_id');
                $table->index('status');
            });
        } catch (\Exception $e) {
            // Indexes may already exist
        }

        // Indexes for teams table
        try {
            Schema::table('teams', function (Blueprint $table) {
                $table->index('owner_id');
                $table->index('status');
                $table->index('division');
            });
        } catch (\Exception $e) {
            // Indexes may already exist
        }

        // Indexes for ballon_dor_nominations table
        try {
            Schema::table('ballon_dor_nominations', function (Blueprint $table) {
                $table->index('season_id');
                $table->index('category');
                $table->index('vote_count');
            });
        } catch (\Exception $e) {
            // Indexes may already exist
        }

        // Indexes for team_marketplace_listings table
        try {
            Schema::table('team_marketplace_listings', function (Blueprint $table) {
                $table->index('status');
                $table->index('listing_type');
                $table->index('team_id');
            });
        } catch (\Exception $e) {
            // Indexes may already exist
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        try {
            Schema::table('federations', function (Blueprint $table) {
                $table->dropIndex(['status']);
                $table->dropIndex(['country']);
                $table->dropIndex(['user_id']);
            });
        } catch (\Exception $e) {}

        try {
            Schema::table('tournaments', function (Blueprint $table) {
                $table->dropIndex(['federation_id']);
                $table->dropIndex(['status']);
                $table->dropIndex(['type']);
                $table->dropIndex(['division']);
                $table->dropIndex(['start_at']);
            });
        } catch (\Exception $e) {}

        try {
            Schema::table('tournament_teams', function (Blueprint $table) {
                $table->dropIndex(['tournament_id']);
                $table->dropIndex(['team_id']);
                $table->dropIndex(['status']);
            });
        } catch (\Exception $e) {}

        try {
            Schema::table('teams', function (Blueprint $table) {
                $table->dropIndex(['owner_id']);
                $table->dropIndex(['status']);
                $table->dropIndex(['division']);
            });
        } catch (\Exception $e) {}

        try {
            Schema::table('ballon_dor_nominations', function (Blueprint $table) {
                $table->dropIndex(['season_id']);
                $table->dropIndex(['category']);
                $table->dropIndex(['vote_count']);
            });
        } catch (\Exception $e) {}

        try {
            Schema::table('team_marketplace_listings', function (Blueprint $table) {
                $table->dropIndex(['status']);
                $table->dropIndex(['listing_type']);
                $table->dropIndex(['team_id']);
            });
        } catch (\Exception $e) {}
    }
};

