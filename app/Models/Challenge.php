<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Challenge extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'creator_id',
        'opponent_id',
        'creator_clan_id',
        'opponent_clan_id',
        'game',
        'bet_amount',
        'status',
        'creator_score',
        'opponent_score',
        'expires_at',
        'creator_screen_stream_url',
        'opponent_screen_stream_url',
        'creator_screen_recording',
        'opponent_screen_recording',
        'is_live',
        'stream_key',
        'rtmp_url',
        'stream_url',
        'live_started_at',
        'live_ended_at',
        'viewer_count',
        'is_live_paused',
    ];

    protected $casts = [
        'bet_amount' => 'decimal:2',
        'creator_score' => 'integer',
        'opponent_score' => 'integer',
        'expires_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'creator_screen_recording' => 'boolean',
        'opponent_screen_recording' => 'boolean',
        'is_live' => 'boolean',
        'viewer_count' => 'integer',
        'live_started_at' => 'datetime',
        'live_ended_at' => 'datetime',
        'is_live_paused' => 'boolean',
    ];

    /**
     * Get the creator of the challenge.
     */
    public function creator()
    {
        return $this->belongsTo(User::class, 'creator_id');
    }

    /**
     * Get the opponent of the challenge.
     */
    public function opponent()
    {
        return $this->belongsTo(User::class, 'opponent_id');
    }

    /**
     * Get the creator clan of the challenge (if type is 'clan').
     */
    public function creatorClan()
    {
        return $this->belongsTo(Clan::class, 'creator_clan_id');
    }

    /**
     * Get the opponent clan of the challenge (if type is 'clan').
     */
    public function opponentClan()
    {
        return $this->belongsTo(Clan::class, 'opponent_clan_id');
    }

    /**
     * Get the messages for this challenge.
     */
    public function messages()
    {
        return $this->hasMany(ChallengeMessage::class)->where('is_deleted', false)->latest();
    }

    /**
     * Get all messages (including deleted) for this challenge.
     */
    public function allMessages()
    {
        return $this->hasMany(ChallengeMessage::class)->latest();
    }

    /**
     * Get the stop request for this challenge.
     */
    public function stopRequest()
    {
        return $this->hasOne(ChallengeStopRequest::class);
    }

    /**
     * Scope to get only open challenges.
     */
    public function scopeOpen($query)
    {
        return $query->where('status', 'open')
            ->where(function($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope to get challenges for a specific user.
     */
    public function scopeForUser($query, $userId)
    {
        return $query->where(function($q) use ($userId) {
            $q->where('creator_id', $userId)
              ->orWhere('opponent_id', $userId);
        });
    }

    /**
     * Scope to get challenges for a specific clan.
     */
    public function scopeForClan($query, $clanId)
    {
        return $query->where('type', 'clan')
            ->where(function($q) use ($clanId) {
                $q->where('creator_clan_id', $clanId)
                  ->orWhere('opponent_clan_id', $clanId);
            });
    }

    /**
     * Scope to get only clan challenges.
     */
    public function scopeClanChallenges($query)
    {
        return $query->where('type', 'clan');
    }

    /**
     * Scope to get only user challenges.
     */
    public function scopeUserChallenges($query)
    {
        return $query->where('type', 'user');
    }
}
