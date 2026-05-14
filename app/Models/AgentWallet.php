<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentWallet extends Model
{
    protected $fillable = [
        'recharge_agent_id', 'balance', 'locked_balance', 'guarantee_deposit',
        'total_deposited', 'total_transferred', 'currency',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'locked_balance' => 'decimal:2',
        'guarantee_deposit' => 'decimal:2',
        'total_deposited' => 'decimal:2',
        'total_transferred' => 'decimal:2',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(RechargeAgent::class, 'recharge_agent_id');
    }

    public function availableBalance(): float
    {
        return max(0, (float) $this->balance - (float) $this->locked_balance);
    }
}
