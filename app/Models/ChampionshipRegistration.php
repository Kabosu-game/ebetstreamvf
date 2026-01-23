<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChampionshipRegistration extends Model
{
    protected $fillable = [
        'championship_id',
        'user_id',
        'team_id',
        'full_name',
        'team_name',
        'team_logo',
        'player_name',
        'player_username',
        'player_id',
        'player_rank',
        'players_list',
        'contact_phone',
        'contact_email',
        'additional_info',
        'accept_terms',
        'status',
        'transaction_id',
        'fee_paid',
        'current_position',
        'matches_won',
        'matches_lost',
        'matches_drawn',
        'points',
        'registered_at',
        'validated_at',
        'paid_at',
    ];

    protected $casts = [
        'fee_paid' => 'decimal:2',
        'matches_won' => 'integer',
        'matches_lost' => 'integer',
        'matches_drawn' => 'integer',
        'points' => 'integer',
        'current_position' => 'integer',
        'players_list' => 'array',
        'accept_terms' => 'boolean',
        'registered_at' => 'datetime',
        'validated_at' => 'datetime',
        'paid_at' => 'datetime',
    ];

    /**
     * Get the championship this registration belongs to
     */
    public function championship(): BelongsTo
    {
        return $this->belongsTo(Championship::class);
    }

    /**
     * Get the user who registered
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the team (if team championship)
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the transaction for payment
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    /**
     * Get matches where this registration is player 1
     */
    public function matchesAsPlayer1(): HasMany
    {
        return $this->hasMany(ChampionshipMatch::class, 'player1_id');
    }

    /**
     * Get matches where this registration is player 2
     */
    public function matchesAsPlayer2(): HasMany
    {
        return $this->hasMany(ChampionshipMatch::class, 'player2_id');
    }

    /**
     * Get all matches for this registration
     */
    public function matches()
    {
        return ChampionshipMatch::where('player1_id', $this->id)
            ->orWhere('player2_id', $this->id);
    }

    /**
     * Check if registration is paid
     */
    public function isPaid(): bool
    {
        return in_array($this->status, ['paid', 'validated']);
    }

    /**
     * Check if registration is validated
     */
    public function isValidated(): bool
    {
        return $this->status === 'validated';
    }
}

