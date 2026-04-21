<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentTier extends Model
{
    protected $fillable = [
        'name',
        'min_monthly_volume',
        'deposit_commission_percentage',
        'withdrawal_commission_percentage',
        'requires_guarantee_amount',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}
