<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArenaMatchPlayer extends Model
{
    protected $fillable = [
        'arena_match_id',
        'user_id',
        'team',
        'player_class',
        'is_mvp',
    ];

    protected $casts = [
        'is_mvp' => 'boolean',
    ];

    public function match(): BelongsTo
    {
        return $this->belongsTo(ArenaMatch::class, 'arena_match_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
