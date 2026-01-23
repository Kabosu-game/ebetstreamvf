<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RechargeAgent extends Model
{
    protected $fillable = [
        'agent_id', // ID aléatoire à 6 chiffres
        'name',
        'phone',
        'status',
        'description',
    ];

    /**
     * Scope pour récupérer uniquement les agents actifs
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    /**
     * Boot du modèle pour générer un ID aléatoire à 6 chiffres lors de la création
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($agent) {
            // Générer un ID aléatoire à 6 chiffres unique
            do {
                $agentId = str_pad(mt_rand(1, 999999), 6, '0', STR_PAD_LEFT);
            } while (self::where('agent_id', $agentId)->exists());
            
            $agent->agent_id = $agentId;
        });
    }
}
