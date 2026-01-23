<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $fillable = [
        'is_ebetstar',
        'username',
        'email',
        'phone',
        'password',
        'promo_code',
        'role',
        'premium_until',
        'used_welcome_code',
        'first_deposit_bonus_applied',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'is_ebetstar' => 'boolean',
        'premium_until' => 'datetime',
    ];

    // Relation avec le wallet
    public function wallet()
    {
        return $this->hasOne(Wallet::class);
    }

    // Relation avec le profil
    public function profile()
    {
        return $this->hasOne(Profile::class);
    }

    // Relation avec les transactions
    public function transactions()
    {
        return $this->hasMany(Transaction::class);
    }

    // Relation avec les inscriptions de championnats
    public function championshipRegistrations()
    {
        return $this->hasMany(ChampionshipRegistration::class);
    }

    /**
     * Vérifie si l'utilisateur a un accès premium actif
     */
    public function hasPremiumAccess()
    {
        if (!$this->premium_until) {
            return false;
        }
        return \Carbon\Carbon::now()->lessThan($this->premium_until);
    }
}
