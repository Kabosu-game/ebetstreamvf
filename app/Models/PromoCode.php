<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PromoCode extends Model
{
    protected $fillable = [
        'code',
        'description',
        'amount', // Ancien champ pour compatibilité
        'bonus', // Ancien champ pour compatibilité
        'welcome_bonus', // Bonus crédité à l'inscription
        'first_deposit_bonus_percentage', // Pourcentage de bonus sur la première recharge
        'premium_days', // Nombre de jours d'accès premium
        'is_welcome_code', // Indique si c'est un code de bienvenue
        'is_active', // Actif ou non
        'uses', // Ancien champ pour compatibilité
        'used_count', // Compteur d'utilisation
        'usage_limit', // Limite d'utilisation (max_uses)
        'max_uses', // Ancien champ pour compatibilité
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_welcome_code' => 'boolean',
        'is_active' => 'boolean',
        'welcome_bonus' => 'decimal:2',
        'first_deposit_bonus_percentage' => 'decimal:2',
        'amount' => 'decimal:2',
        'bonus' => 'decimal:2',
    ];

    /**
     * Vérifie si le code peut être utilisé
     */
    public function canBeUsed()
    {
        if (!$this->is_active) {
            return false;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        $maxUses = $this->usage_limit ?? $this->max_uses ?? 1;
        $usedCount = $this->used_count ?? $this->uses ?? 0;

        return $usedCount < $maxUses;
    }

    /**
     * Incrémente le compteur d'utilisation
     */
    public function incrementUsage()
    {
        $this->increment('used_count');
        if ($this->uses !== null) {
            $this->increment('uses');
        }
    }
}
