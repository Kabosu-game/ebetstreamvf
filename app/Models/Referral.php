<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Referral extends Model
{
    protected $fillable = ['referrer_id', 'referred_id', 'bonus'];

    /**
     * Relation avec le parrain
     */
    public function referrer()
    {
        return $this->belongsTo(User::class, 'referrer_id');
    }

    /**
     * Relation avec le filleul
     */
    public function referred()
    {
        return $this->belongsTo(User::class, 'referred_id');
    }
}
