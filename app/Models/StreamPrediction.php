<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StreamPrediction extends Model
{
    protected $fillable = [
        'stream_id', 'predictor_user_id', 'streamer_user_id',
        'credits_amount', 'platform_commission', 'streamer_share', 'platform_share',
        'commission_rate', 'streamer_percent', 'prediction_type', 'status',
    ];

    protected $casts = ['credits_amount' => 'float', 'streamer_share' => 'float', 'platform_share' => 'float'];

    public function stream()    { return $this->belongsTo(Stream::class); }
    public function predictor() { return $this->belongsTo(User::class, 'predictor_user_id'); }
    public function streamer()  { return $this->belongsTo(User::class, 'streamer_user_id'); }
}
