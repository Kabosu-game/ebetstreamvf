<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BallonDorVote extends Model
{
    use HasFactory;

    protected $fillable = [
        'season_id',
        'nomination_id',
        'voter_id',
        'voter_type',
        'category',
        'points',
        'comment',
    ];

    protected $casts = [
        'points' => 'integer',
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
     * Get the nomination.
     */
    public function nomination()
    {
        return $this->belongsTo(BallonDorNomination::class);
    }

    /**
     * Get the voter (polymorphic).
     */
    public function voter()
    {
        return $this->morphTo();
    }
}

