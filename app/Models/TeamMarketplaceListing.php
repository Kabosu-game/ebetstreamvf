<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TeamMarketplaceListing extends Model
{
    protected $fillable = [
        'team_id',
        'seller_id',
        'listing_type',
        'price',
        'loan_fee',
        'loan_duration_days',
        'conditions',
        'status',
        'buyer_id',
        'sold_at',
        'loan_start_date',
        'loan_end_date',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'loan_fee' => 'decimal:2',
        'loan_duration_days' => 'integer',
        'sold_at' => 'datetime',
        'loan_start_date' => 'datetime',
        'loan_end_date' => 'datetime',
    ];

    // Relations
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'seller_id');
    }

    public function buyer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'buyer_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForSale($query)
    {
        return $query->where('listing_type', 'sale');
    }

    public function scopeForLoan($query)
    {
        return $query->where('listing_type', 'loan');
    }

    // Helper methods
    public function isAvailable(): bool
    {
        return $this->status === 'active';
    }
}

