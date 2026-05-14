<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SponsoredMatch extends Model
{
    protected $fillable = [
        'championship_id', 'organizer_user_id', 'title', 'description', 'game',
        'prize_pool_total', 'players_prize', 'organizer_prize', 'platform_prize',
        'status', 'distributed', 'starts_at', 'ends_at',
    ];

    protected $casts = [
        'distributed' => 'boolean',
        'starts_at'   => 'datetime',
        'ends_at'     => 'datetime',
    ];

    public function organizer()    { return $this->belongsTo(User::class, 'organizer_user_id'); }
    public function championship() { return $this->belongsTo(Championship::class); }
    public function participants() { return $this->hasMany(SponsoredMatchParticipant::class); }
}
