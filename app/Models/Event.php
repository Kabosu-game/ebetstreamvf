<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;

class Event extends Model
{
    protected $fillable = [
        'title',
        'description',
        'start_at',
        'end_at',
        'location',
        'image',
        'status',
        'type',
        'max_participants',
        'registration_deadline',
    ];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'registration_deadline' => 'datetime',
        'max_participants' => 'integer',
    ];

    /**
     * Scope pour les événements à venir
     */
    public function scopeUpcoming($query)
    {
        return $query->where('start_at', '>', now());
    }

    /**
     * Scope pour les événements en cours
     */
    public function scopeOngoing($query)
    {
        return $query->where('start_at', '<=', now())
            ->where(function($q) {
                $q->whereNull('end_at')
                  ->orWhere('end_at', '>=', now());
            });
    }

    /**
     * Scope pour les événements passés
     */
    public function scopePast($query)
    {
        return $query->where(function($q) {
            $q->whereNotNull('end_at')
              ->where('end_at', '<', now());
        });
    }

    /**
     * Vérifier si l'événement est à venir
     */
    public function isUpcoming(): bool
    {
        return $this->start_at > now();
    }

    /**
     * Vérifier si l'événement est en cours
     */
    public function isOngoing(): bool
    {
        $now = now();
        return $this->start_at <= $now && 
               ($this->end_at === null || $this->end_at >= $now);
    }

    /**
     * Vérifier si l'événement est passé
     */
    public function isPast(): bool
    {
        return $this->end_at !== null && $this->end_at < now();
    }

    /**
     * Obtenir l'URL de l'image
     */
    public function getImageUrlAttribute(): ?string
    {
        if (!$this->image) {
            return null;
        }

        if (filter_var($this->image, FILTER_VALIDATE_URL)) {
            return $this->image;
        }

        return asset('storage/' . $this->image);
    }

    /**
     * Relation avec les inscriptions
     */
    public function registrations()
    {
        return $this->hasMany(EventRegistration::class);
    }

    /**
     * Obtenir le nombre d'inscrits
     */
    public function getRegistrationsCountAttribute(): int
    {
        return $this->registrations()->count();
    }
}
