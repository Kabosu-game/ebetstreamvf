<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArenaPlayerProfile extends Model
{
    protected $fillable = [
        'user_id',
        'player_class',
        'rank',
        'league_tier',
        'level',
        'mmr',
        'points',
        'matches_played',
        'matches_won',
        'matches_lost',
    ];

    protected $casts = [
        'level' => 'integer',
        'mmr' => 'integer',
        'points' => 'integer',
        'matches_played' => 'integer',
        'matches_won' => 'integer',
        'matches_lost' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
