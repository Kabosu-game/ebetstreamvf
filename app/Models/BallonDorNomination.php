<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BallonDorNomination extends Model
{
    use HasFactory;

    protected $fillable = [
        'season_id',
        'category',
        'category_label',
        'nominee_id',
        'nominee_type',
        'description',
        'achievements',
        'vote_count',
        'rank',
        'is_winner',
    ];

    protected $casts = [
        'vote_count' => 'integer',
        'rank' => 'integer',
        'is_winner' => 'boolean',
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
     * Get the nominee (polymorphic).
     */
    public function nominee()
    {
        return $this->morphTo();
    }

    /**
     * Get all votes for this nomination.
     */
    public function votes()
    {
        return $this->hasMany(BallonDorVote::class, 'nomination_id');
    }

    /**
     * Increment vote count.
     */
    public function incrementVoteCount()
    {
        $this->increment('vote_count');
    }

    /**
     * Decrement vote count.
     */
    public function decrementVoteCount()
    {
        $this->decrement('vote_count');
    }

    /**
     * Get nominee name.
     */
    public function getNomineeNameAttribute(): string
    {
        if (!$this->nominee) {
            return 'Unknown';
        }

        switch ($this->nominee_type) {
            case 'App\Models\User':
                return $this->nominee->username ?? $this->nominee->name ?? 'Unknown User';
            case 'App\Models\Clan':
                return $this->nominee->name ?? 'Unknown Clan';
            default:
                return 'Unknown';
        }
    }

    /**
     * Get nominee avatar/logo.
     */
    public function getNomineeImageAttribute(): ?string
    {
        if (!$this->nominee) {
            return null;
        }

        switch ($this->nominee_type) {
            case 'App\Models\User':
                // Return user avatar if available
                return null; // You can add avatar logic here
            case 'App\Models\Clan':
                // Return clan logo if available
                return null; // You can add logo logic here
            default:
                return null;
        }
    }
}

