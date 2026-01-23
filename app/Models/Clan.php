<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Clan extends Model
{
    protected $fillable = [
        'name',
        'logo',
        'description',
        'leader_id',
        'status',
        'member_count',
        'max_members',
    ];

    protected $casts = [
        'member_count' => 'integer',
        'max_members' => 'integer',
    ];

    // Relations
    public function leader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'leader_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'clan_user')
            ->withTimestamps();
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(ClanLeaderCandidate::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ClanMessage::class)->where('is_deleted', false)->latest();
    }

    public function allMessages(): HasMany
    {
        return $this->hasMany(ClanMessage::class)->latest();
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    // Helper methods
    public function isMember($userId): bool
    {
        return $this->members()->where('user_id', $userId)->exists();
    }

    public function isLeader($userId): bool
    {
        return $this->leader_id === $userId;
    }

    public function canJoin(): bool
    {
        return $this->member_count < $this->max_members;
    }
}
