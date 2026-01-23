<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AgentRequest extends Model
{
    protected $fillable = [
        'name',
        'whatsapp',
        'email',
        'phone',
        'birth_date',
        'city',
        'occupation',
        'experience',
        'skills',
        'availability',
        'working_hours',
        'motivation',
        'message',
        'has_id_card',
        'has_business_license',
        'agree_terms',
        'status',
        'user_id',
    ];

    /**
     * Relation avec l'utilisateur (optionnel)
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope pour récupérer uniquement les demandes en attente
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope pour récupérer uniquement les demandes approuvées
     */
    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    /**
     * Scope pour récupérer uniquement les demandes rejetées
     */
    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }
}





