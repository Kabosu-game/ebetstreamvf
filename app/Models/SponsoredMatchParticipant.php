<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SponsoredMatchParticipant extends Model
{
    protected $fillable = ['sponsored_match_id', 'user_id', 'placement', 'prize_received'];
    protected $casts    = ['prize_received' => 'float'];

    public function match() { return $this->belongsTo(SponsoredMatch::class, 'sponsored_match_id'); }
    public function user()  { return $this->belongsTo(User::class); }
}
