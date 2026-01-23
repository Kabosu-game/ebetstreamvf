<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Season extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'start_date',
        'end_date',
        'voting_start_date',
        'voting_end_date',
        'status',
        'is_current',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'voting_start_date' => 'date',
        'voting_end_date' => 'date',
        'is_current' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($season) {
            if (empty($season->slug)) {
                $season->slug = Str::slug($season->name);
            }
        });
    }

    /**
     * Get all nominations for this season.
     */
    public function nominations()
    {
        return $this->hasMany(BallonDorNomination::class);
    }

    /**
     * Get nominations by category.
     */
    public function nominationsByCategory($category)
    {
        return $this->nominations()->where('category', $category);
    }

    /**
     * Get all votes for this season.
     */
    public function votes()
    {
        return $this->hasMany(BallonDorVote::class);
    }

    /**
     * Get voting rules for this season.
     */
    public function votingRules()
    {
        return $this->hasMany(BallonDorVotingRule::class);
    }

    /**
     * Get voting rule for a specific category.
     */
    public function getVotingRule($category)
    {
        return $this->votingRules()->where('category', $category)->first();
    }

    /**
     * Check if voting is currently open.
     */
    public function isVotingOpen(): bool
    {
        if ($this->status !== 'voting') {
            return false;
        }

        $now = now();
        if ($this->voting_start_date && $now < $this->voting_start_date) {
            return false;
        }
        if ($this->voting_end_date && $now > $this->voting_end_date) {
            return false;
        }

        return true;
    }

    /**
     * Get current season.
     */
    public static function current()
    {
        return static::where('is_current', true)->first();
    }
}

