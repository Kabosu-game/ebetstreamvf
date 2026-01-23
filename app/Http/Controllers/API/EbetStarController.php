<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class EbetStarController extends Controller
{
    /**
     * Get all ebetstars (users marked as is_ebetstar = true)
     */
    public function index(Request $request)
    {
        try {
            $limit = $request->input('limit', 100);
            
            $ebetstars = User::where('is_ebetstar', true)
                ->where(function($query) {
                    $query->whereNull('role')
                          ->orWhere('role', '!=', 'admin');
                })
                ->with(['wallet', 'profile'])
                ->get()
                ->sortByDesc(function ($user) {
                    return $user->wallet ? $user->wallet->balance : 0;
                })
                ->take($limit)
                ->values()
                ->map(function ($user) {
                    $profile = $user->profile;
                    $wallet = $user->wallet;
                    $profilePhoto = $profile && $profile->profile_photo ? $profile->profile_photo : null;
                    $profilePhotoUrl = $profilePhoto ? url('/api/storage/profiles/' . basename($profilePhoto)) : null;
                    
                    // Helper function to return null if empty string
                    $getValue = function($value) {
                        return ($value && trim($value)) ? trim($value) : null;
                    };
                    
                    return [
                        'id' => $user->id,
                        'username' => $user->username,
                        'name' => $profile ? $getValue($profile->full_name) : null,
                        'full_name' => $profile ? $getValue($profile->full_name) : null,
                        'email' => $user->email,
                        'country' => $profile ? $getValue($profile->country) : null,
                        'avatar' => $profilePhoto,
                        'avatar_url' => $profilePhotoUrl,
                        'profile_photo_url' => $profilePhotoUrl,
                        'score' => $wallet ? $wallet->balance : 0,
                        'wallet' => $wallet ? [
                            'balance' => $wallet->balance
                        ] : null,
                    ];
                });

            return response()->json([
                'success' => true,
                'data' => $ebetstars
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Error loading ebetstars: ' . $e->getMessage()
            ], 500);
        }
    }
}

