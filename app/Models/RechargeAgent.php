<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class RechargeAgent extends Model
{
    protected $fillable = [
        'agent_id',
        'user_id',
        'agent_tier_id',
        'name',
        'phone',
        'status',
        'description',
        'kyc_verified',
        'contract_signed_at',
        'rating_avg',
        'rating_count',
    ];

    protected $casts = [
        'kyc_verified' => 'boolean',
        'contract_signed_at' => 'datetime',
        'rating_avg' => 'decimal:2',
        'rating_count' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tier(): BelongsTo
    {
        return $this->belongsTo(AgentTier::class, 'agent_tier_id');
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(AgentWallet::class);
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(AgentTransfer::class);
    }

    public function cryptoDeposits(): HasMany
    {
        return $this->hasMany(AgentCryptoDeposit::class);
    }

    public function ratings(): HasMany
    {
        return $this->hasMany(AgentRating::class);
    }

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($agent) {
            if (empty($agent->agent_id)) {
                do {
                    $agentId = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
                } while (self::where('agent_id', $agentId)->exists());
                $agent->agent_id = $agentId;
            }
        });
    }
}
