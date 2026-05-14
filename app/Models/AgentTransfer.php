<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentTransfer extends Model
{
    protected $fillable = [
        'recharge_agent_id', 'user_id', 'type', 'amount', 'commission',
        'status', 'reference', 'withdrawal_code_id', 'meta',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'commission' => 'decimal:2',
        'meta' => 'array',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(RechargeAgent::class, 'recharge_agent_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function withdrawalCode(): BelongsTo
    {
        return $this->belongsTo(WithdrawalCode::class);
    }
}
