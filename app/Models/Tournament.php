<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Tournament extends Model
{
    protected $fillable = [
        'federation_id',
        'creator_id',
        'title',
        'name',
        'game',
        'entry_fee',
        'reward',
        'type',
        'division',
        'max_participants',
        'rules',
        'description',
        'status',
        'start_at',
        'starts_at',
        'end_at',
        'ends_at',
        'settings'
    ];

    protected $casts = [
        'settings' => 'json',
        'entry_fee' => 'decimal:2',
        'reward' => 'decimal:2',
        'start_at' => 'datetime',
        'starts_at' => 'datetime',
        'end_at' => 'datetime',
        'ends_at' => 'datetime'
    ];

    /**
     * Get the federation that owns this tournament.
     */
    public function federation()
    {
        return $this->belongsTo(Federation::class);
    }

    /**
     * Get the creator of the tournament.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Get all participants (for individual tournaments).
     */
    public function participants()
    {
        return $this->belongsToMany(User::class, 'tournament_user');
    }

    /**
     * Get all teams participating in this tournament.
     */
    public function teams()
    {
        return $this->belongsToMany(Team::class, 'tournament_teams')
            ->withPivot('registered_by', 'status', 'registered_at')
            ->withTimestamps();
    }

    /**
     * Get confirmed teams only.
     */
    public function confirmedTeams()
    {
        return $this->teams()->wherePivot('status', 'confirmed');
    }

    /**
     * Check if tournament requires teams.
     */
    public function requiresTeam(): bool
    {
        return $this->type === 'team';
    }
}
