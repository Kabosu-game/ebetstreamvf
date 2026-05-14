<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StreamDonation extends Model
{
    protected $fillable = [
        'stream_id', 'donor_user_id', 'streamer_user_id',
        'amount', 'streamer_amount', 'platform_amount',
        'streamer_percent', 'message', 'status',
    ];

    protected $casts = ['amount' => 'float', 'streamer_amount' => 'float', 'platform_amount' => 'float'];

    public function stream()      { return $this->belongsTo(Stream::class); }
    public function donor()       { return $this->belongsTo(User::class, 'donor_user_id'); }
    public function streamer()    { return $this->belongsTo(User::class, 'streamer_user_id'); }
}
