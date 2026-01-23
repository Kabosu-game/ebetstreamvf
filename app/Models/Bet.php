<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bet extends Model
{
    protected $fillable = [
        'user_id',
        'game_match_id',
        'challenge_id',
        'championship_match_id',
        'bet_type',
        'amount',
        'potential_win',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'potential_win' => 'decimal:2',
    ];

    // Relation avec l'utilisateur
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    // Relation avec le match (pour les anciens paris)
    public function gameMatch()
    {
        return $this->belongsTo(GameMatch::class);
    }

    // Relation avec le défi (pour les nouveaux paris)
    public function challenge()
    {
        return $this->belongsTo(Challenge::class);
    }

    // Relation avec le match de championnat
    public function championshipMatch()
    {
        return $this->belongsTo(ChampionshipMatch::class);
    }

    // Scope pour les paris sur les matches
    public function scopeOnMatches($query)
    {
        return $query->whereNotNull('game_match_id');
    }

    // Scope pour les paris sur les défis
    public function scopeOnChallenges($query)
    {
        return $query->whereNotNull('challenge_id');
    }

    // Scope pour les paris sur les matchs de championnat
    public function scopeOnChampionshipMatches($query)
    {
        return $query->whereNotNull('championship_match_id');
    }
}
