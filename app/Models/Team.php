<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Team extends Model
{
    protected $fillable = [
        'name',
        'logo',
        'owner_id',
        'description',
        'status',
        'division',
    ];

    protected $casts = [
        'owner_id' => 'integer',
    ];

    // Relations
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'team_user')
            ->withTimestamps();
    }

    public function marketplaceListings(): HasMany
    {
        return $this->hasMany(TeamMarketplaceListing::class);
    }

    public function activeListing(): HasMany
    {
        return $this->hasMany(TeamMarketplaceListing::class)->where('status', 'active');
    }

    // Scopes
    public function scopeAvailable($query)
    {
        return $query->where('status', 'active');
    }

    // Helper methods
    public function isOwner($userId): bool
    {
        return $this->owner_id === $userId;
    }
}
