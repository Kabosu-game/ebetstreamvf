<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertificationRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'status',
        'full_name',
        'birth_date',
        'date_of_birth', // For profile certification
        'id_type', // For profile certification
        'id_number', // For profile certification
        'country',
        'city',
        'phone',
        'professional_email',
        'username',
        'experience',
        'availability',
        'technical_skills',
        'id_card_front',
        'id_card_back',
        'selfie',
        'specific_documents',
        'event_proof',
        'tournament_structure',
        'professional_contacts',
        'mini_cv',
        'presentation_video',
        'community_proof',
        'social_media_links',
        'audience_stats',
        'previous_media',
        'submitted_at',
        'reviewed_at',
        'test_completed_at',
        'interview_completed_at',
        'approved_at',
        'rejected_at',
        'reviewed_by',
        'rejection_reason',
        'notes',
        'verification_data', // For profile certification
    ];

    protected $casts = [
        'birth_date' => 'date',
        'date_of_birth' => 'date', // For profile certification
        'submitted_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'test_completed_at' => 'datetime',
        'interview_completed_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'specific_documents' => 'array',
        'professional_contacts' => 'array',
        'social_media_links' => 'array',
        'audience_stats' => 'array',
        'verification_data' => 'array', // For profile certification
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeUnderReview($query)
    {
        return $query->where('status', 'under_review');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected($query)
    {
        return $query->where('status', 'rejected');
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }
}
