<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    protected $fillable = [
        'wallet_id',
        'user_id',
        'type',
        'amount',
        'status',
        'provider',
        'txid',
        'meta',
    ];

    protected $casts = [
        'meta' => 'json'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
