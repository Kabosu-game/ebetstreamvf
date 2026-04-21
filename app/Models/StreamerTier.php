<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StreamerTier extends Model
{
    protected $fillable = [
        'name',
        'min_followers',
        'max_followers',
        'commission_percentage',
        'benefits',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'benefits' => 'array',
        'is_active' => 'boolean',
    ];
}
