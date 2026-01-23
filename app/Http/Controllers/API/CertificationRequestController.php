<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CertificationRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

class CertificationRequestController extends Controller
{
    /**
     * Submit a certification request
     */
    public function store(Request $request)
    {
        $user = $request->user();
        $userId = $request->input('user_id');
        
        // If user is authenticated, use authenticated user
        // Otherwise, use provided user_id (for guest submissions)
        if ($user) {
            $userId = $user->id;
        } elseif (!$userId) {
            // For guest submissions, user_id is optional
            $userId = null;
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:organizer,referee,ambassador',
            'user_id' => 'nullable|exists:users,id',
            'full_name' => 'required|string|max:255',
            'birth_date' => 'nullable|date',
            'country' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:255',
            'phone' => 'required|string|max:20',
            'professional_email' => 'required|email|max:255',
            'username' => 'nullable|string|max:255',
            'experience' => 'nullable|string',
            'availability' => 'nullable|string',
            'technical_skills' => 'nullable|string',
            'id_card_front' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'id_card_back' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'selfie' => 'required|file|mimes:jpg,jpeg,png|max:5120',
            // Organizer specific
            'event_proof' => 'nullable|string',
            'tournament_structure' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:10240',
            'professional_contacts' => 'nullable|string',
            // Referee specific
            'mini_cv' => 'nullable|string',
            'presentation_video' => 'nullable|url|max:500',
            'community_proof' => 'nullable|string',
            // Ambassador specific
            'social_media_links' => 'nullable|string',
            'audience_stats' => 'nullable|string',
            'previous_media' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            $errors = $validator->errors();
            $errorMessages = [];
            foreach ($errors->all() as $message) {
                $errorMessages[] = $message;
            }
            return response()->json([
                'success' => false,
                'message' => 'Validation failed: ' . implode(', ', $errorMessages),
                'errors' => $errors
            ], 422);
        }

        // Check if user already has a pending request of this type (only if user_id is provided)
        if ($userId) {
            $existingRequest = CertificationRequest::where('user_id', $userId)
                ->where('type', $request->type)
                ->whereIn('status', ['pending', 'under_review', 'test_pending', 'interview_pending'])
                ->first();

            if ($existingRequest) {
                return response()->json([
                    'success' => false,
                    'message' => 'You already have a pending certification request of this type.'
                ], 400);
            }
        }

        try {
            // Store files
            $idCardFrontPath = null;
            $idCardBackPath = null;
            $selfiePath = null;
            $tournamentStructurePath = null;

            if ($request->hasFile('id_card_front')) {
                $idCardFrontPath = $request->file('id_card_front')->store('certifications/id_cards', 'public');
            }
            if ($request->hasFile('id_card_back')) {
                $idCardBackPath = $request->file('id_card_back')->store('certifications/id_cards', 'public');
            }
            if ($request->hasFile('selfie')) {
                $selfiePath = $request->file('selfie')->store('certifications/selfies', 'public');
            }
            if ($request->hasFile('tournament_structure')) {
                $tournamentStructurePath = $request->file('tournament_structure')->store('certifications/tournament_structures', 'public');
            }

            // Generate username if not provided (for guest submissions)
            $username = $request->username;
            if (empty($username)) {
                // Use email as username or generate one
                $username = $request->professional_email;
            }

            // Create certification request
            $certificationRequest = CertificationRequest::create([
                'user_id' => $userId,
                'type' => $request->type,
                'status' => 'pending',
                'full_name' => $request->full_name,
                'birth_date' => $request->birth_date,
                'country' => $request->country,
                'city' => $request->city,
                'phone' => $request->phone,
                'professional_email' => $request->professional_email,
                'username' => $username,
                'experience' => $request->experience,
                'availability' => $request->availability,
                'technical_skills' => $request->technical_skills,
                'id_card_front' => $idCardFrontPath,
                'id_card_back' => $idCardBackPath,
                'selfie' => $selfiePath,
                'event_proof' => $request->event_proof,
                'tournament_structure' => $tournamentStructurePath,
                'professional_contacts' => $request->professional_contacts,
                'mini_cv' => $request->mini_cv,
                'presentation_video' => $request->presentation_video,
                'community_proof' => $request->community_proof,
                'social_media_links' => $request->social_media_links,
                'audience_stats' => $request->audience_stats,
                'previous_media' => $request->previous_media,
                'submitted_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Certification request submitted successfully. We will review it and contact you soon.',
                'data' => $certificationRequest
            ], 201);

        } catch (\Exception $e) {
            // Clean up uploaded files if creation fails
            if (isset($idCardFrontPath)) Storage::disk('public')->delete($idCardFrontPath);
            if (isset($idCardBackPath)) Storage::disk('public')->delete($idCardBackPath);
            if (isset($selfiePath)) Storage::disk('public')->delete($selfiePath);
            if (isset($tournamentStructurePath)) Storage::disk('public')->delete($tournamentStructurePath);

            return response()->json([
                'success' => false,
                'message' => 'Error submitting certification request: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get user's certification requests (authenticated users only)
     */
    public function index(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $requests = CertificationRequest::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $requests
        ]);
    }

    /**
     * Get a specific certification request (authenticated users only)
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();

        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 401);
        }

        $certificationRequest = CertificationRequest::where('user_id', $user->id)
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $certificationRequest
        ]);
    }
}
