<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentRating extends Model
{
    protected $fillable = ['recharge_agent_id', 'user_id', 'rating', 'comment'];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(RechargeAgent::class, 'recharge_agent_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
