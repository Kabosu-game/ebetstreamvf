<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BallonDorVotingRule extends Model
{
    use HasFactory;

    protected $fillable = [
        'season_id',
        'category',
        'community_can_vote',
        'players_can_vote',
        'federations_can_vote',
        'min_participations',
        'max_votes_per_category',
        'additional_rules',
    ];

    protected $casts = [
        'community_can_vote' => 'boolean',
        'players_can_vote' => 'boolean',
        'federations_can_vote' => 'boolean',
        'min_participations' => 'integer',
        'max_votes_per_category' => 'integer',
        'additional_rules' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the season.
     */
    public function season()
    {
        return $this->belongsTo(Season::class);
    }

    /**
     * Check if a user can vote based on rules.
     */
    public function canUserVote($user): bool
    {
        // Check if community can vote
        if ($this->community_can_vote) {
            return true;
        }

        // Check if players can vote
        if ($this->players_can_vote) {
            // You can add logic here to check if user is a player
            // For example, check if user has participated in challenges/tournaments
            return true; // Simplified for now
        }

        return false;
    }

    /**
     * Check if a federation can vote.
     */
    public function canFederationVote($federation): bool
    {
        if (!$this->federations_can_vote) {
            return false;
        }

        // Check if federation is approved
        if ($federation->status !== 'approved') {
            return false;
        }

        return true;
    }
}

