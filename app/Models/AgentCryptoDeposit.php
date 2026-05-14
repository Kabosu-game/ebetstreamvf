<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentCryptoDeposit extends Model
{
    protected $fillable = [
        'recharge_agent_id', 'amount', 'crypto_network', 'tx_hash',
        'status', 'admin_notes', 'credited_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'credited_at' => 'datetime',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(RechargeAgent::class, 'recharge_agent_id');
    }
}
