<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Championship extends Model
{
    protected $fillable = [
        'name',
        'game',
        'division',
        'description',
        'rules',
        'registration_fee',
        'total_prize_pool',
        'prize_distribution',
        'registration_start_date',
        'registration_end_date',
        'start_date',
        'end_date',
        'max_participants',
        'min_participants',
        'status',
        'banner_image',
        'thumbnail_image',
        'is_active',
        'current_round',
        'admin_notes',
    ];

    protected $casts = [
        'registration_fee' => 'decimal:2',
        'total_prize_pool' => 'decimal:2',
        'prize_distribution' => 'array',
        'registration_start_date' => 'date',
        'registration_end_date' => 'date',
        'start_date' => 'date',
        'end_date' => 'date',
        'is_active' => 'boolean',
        'current_round' => 'integer',
        'max_participants' => 'integer',
        'min_participants' => 'integer',
    ];

    /**
     * Get all registrations for this championship
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(ChampionshipRegistration::class);
    }

    /**
     * Get validated registrations only
     */
    public function validatedRegistrations(): HasMany
    {
        return $this->hasMany(ChampionshipRegistration::class)->where('status', 'validated');
    }

    /**
     * Get paid registrations
     */
    public function paidRegistrations(): HasMany
    {
        return $this->hasMany(ChampionshipRegistration::class)->where('status', 'paid');
    }

    /**
     * Get all matches for this championship
     */
    public function matches(): HasMany
    {
        return $this->hasMany(ChampionshipMatch::class);
    }

    /**
     * Get the current round matches
     */
    public function currentRoundMatches(): HasMany
    {
        return $this->hasMany(ChampionshipMatch::class)->where('round_number', $this->current_round);
    }

    /**
     * Check if registration is open
     */
    public function isRegistrationOpen(): bool
    {
        // Check if championship is active
        if (!$this->is_active) {
            return false;
        }

        // Check status - must be registration_open or draft
        $statusValid = in_array($this->status, ['registration_open', 'draft']);
        if (!$statusValid) {
            return false;
        }

        // Check that championship hasn't started yet
        $notStarted = !in_array($this->status, ['started', 'finished', 'cancelled']);
        if (!$notStarted) {
            return false;
        }

        // Check dates - use startOfDay() to compare dates properly (ignoring time)
        $now = now()->startOfDay();
        $startDate = \Carbon\Carbon::parse($this->registration_start_date)->startOfDay();
        $endDate = \Carbon\Carbon::parse($this->registration_end_date)->endOfDay();
        
        // Check if current date is within registration period
        $dateValid = $now->gte($startDate) && $now->lte($endDate);
        
        return $dateValid;
    }

    /**
     * Check if championship has started
     */
    public function hasStarted(): bool
    {
        return in_array($this->status, ['started', 'finished']) && now()->gte($this->start_date);
    }

    /**
     * Check if championship is full
     */
    public function isFull(): bool
    {
        return $this->validatedRegistrations()->count() >= $this->max_participants;
    }

    /**
     * Get available spots
     */
    public function getAvailableSpots(): int
    {
        return max(0, $this->max_participants - $this->validatedRegistrations()->count());
    }
}

