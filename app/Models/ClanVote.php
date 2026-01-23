<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClanVote extends Model
{
    protected $fillable = [
        'clan_id',
        'candidate_id',
        'voter_id',
    ];

    public function clan(): BelongsTo
    {
        return $this->belongsTo(Clan::class);
    }

    public function candidate(): BelongsTo
    {
        return $this->belongsTo(ClanLeaderCandidate::class, 'candidate_id');
    }

    public function voter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voter_id');
    }
}
