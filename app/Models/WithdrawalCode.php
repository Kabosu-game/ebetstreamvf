<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WithdrawalCode extends Model
{
    protected $fillable = [
        'code',
        'amount',
        'user_id',
        'recharge_agent_id',
        'status',
        'expires_at',
        'completed_at',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    /**
     * Relation avec l'utilisateur
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Relation avec l'agent de recharge
     */
    public function rechargeAgent()
    {
        return $this->belongsTo(RechargeAgent::class);
    }

    /**
     * Générer un code unique de retrait
     */
    public static function generateUniqueCode()
    {
        do {
            $code = 'WD' . strtoupper(uniqid()) . rand(1000, 9999);
        } while (self::where('code', $code)->exists());
        
        return $code;
    }

    /**
     * Scope pour les codes en attente
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope pour les codes expirés
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<', now())->where('status', 'pending');
    }
}
