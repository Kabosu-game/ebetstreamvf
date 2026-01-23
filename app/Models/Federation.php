<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Federation extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'description',
        'logo',
        'website',
        'email',
        'phone',
        'country',
        'city',
        'address',
        'status',
        'rejection_reason',
        'settings',
    ];

    protected $casts = [
        'settings' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Boot the model.
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($federation) {
            if (empty($federation->slug)) {
                $federation->slug = Str::slug($federation->name);
            }
        });
    }

    /**
     * Get the user (admin) of the federation.
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get all tournaments for this federation.
     */
    public function tournaments()
    {
        return $this->hasMany(Tournament::class);
    }

    /**
     * Get approved tournaments only.
     */
    public function approvedTournaments()
    {
        return $this->hasMany(Tournament::class)->where('status', '!=', 'cancelled');
    }

    /**
     * Check if federation is approved.
     */
    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * Get logo URL.
     */
    public function getLogoUrlAttribute(): ?string
    {
        if (!$this->logo) {
            return null;
        }

        if (str_starts_with($this->logo, 'http')) {
            return $this->logo;
        }

        return asset('storage/' . $this->logo);
    }
}

