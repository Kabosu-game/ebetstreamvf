<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PaymentMethod extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'type', // 'deposit' or 'withdrawal'
        'method_key', // 'crypto', 'cash', 'bank_transfer', 'mobile_money'
        'is_active',
        'min_amount',
        'max_amount',
        'fee_percentage',
        'fee_fixed',
        'crypto_address',
        'crypto_network',
        'mobile_money_provider', // 'MTN', 'Orange', 'Moov'
        'bank_name',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'fee_percentage' => 'decimal:2',
        'fee_fixed' => 'decimal:2',
    ];

    /**
     * Scope for active methods
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for deposit methods
     */
    public function scopeDeposits($query)
    {
        return $query->where('type', 'deposit');
    }

    /**
     * Scope for withdrawal methods
     */
    public function scopeWithdrawals($query)
    {
        return $query->where('type', 'withdrawal');
    }

    /**
     * Scope by method key
     */
    public function scopeByMethodKey($query, $key)
    {
        return $query->where('method_key', $key);
    }
}


