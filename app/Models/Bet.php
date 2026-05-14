<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bet extends Model
{
    protected $fillable = [
        'user_id',
        'challenge_id',
        'championship_match_id',
        'arena_match_id',
        'bet_type',
        'amount',
        'potential_win',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'potential_win' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function challenge()
    {
        return $this->belongsTo(Challenge::class);
    }

    public function championshipMatch()
    {
        return $this->belongsTo(ChampionshipMatch::class);
    }

    public function arenaMatch()
    {
        return $this->belongsTo(ArenaMatch::class);
    }

    public function scopeOnChallenges($query)
    {
        return $query->whereNotNull('challenge_id');
    }

    public function scopeOnChampionshipMatches($query)
    {
        return $query->whereNotNull('championship_match_id');
    }

    public function scopeOnArenaMatches($query)
    {
        return $query->whereNotNull('arena_match_id');
    }
}
