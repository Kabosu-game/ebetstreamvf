<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProfileController extends Controller
{
    /**
     * Récupérer le profil de l'utilisateur connecté
     */
    public function show(Request $request)
    {
        $user = $request->user();
        
        $profile = Profile::where('user_id', $user->id)->first();
        
        if (!$profile) {
            // Créer un profil par défaut
            $profile = Profile::create([
                'user_id' => $user->id,
                'username' => $user->username,
                'full_name' => null,
                'in_game_pseudo' => null,
                'status' => null,
                'country' => null,
                'wins' => 0,
                'losses' => 0,
                'ratio' => 0,
                'tournaments_won' => 0,
                'global_score' => 0,
            ]);
            
            // Générer le QR code et l'URL du profil
            $this->generateProfileQR($profile);
        }
        
        // Régénérer le QR code si nécessaire
        if (!$profile->qr_code || !$profile->profile_url) {
            $this->generateProfileQR($profile);
            $profile->refresh();
        }

        $profileData = $profile->load('user')->toArray();
        
        // Ajouter l'URL complète de la photo de profil
        // Utiliser l'API route pour servir les images directement
        $baseUrl = rtrim(config('app.url'), '/') . '/api';
        
        if ($profile->profile_photo) {
            // Vérifier si le fichier existe
            if (Storage::disk('public')->exists($profile->profile_photo)) {
                // Extraire le nom du fichier depuis le chemin
                $filename = basename($profile->profile_photo);
                $profileData['profile_photo_url'] = $baseUrl . '/storage/profiles/' . $filename;
            } else {
                $profileData['profile_photo_url'] = null;
            }
        } elseif ($profile->avatar) {
            if (Storage::disk('public')->exists($profile->avatar)) {
                $filename = basename($profile->avatar);
                $profileData['profile_photo_url'] = $baseUrl . '/storage/profiles/' . $filename;
            } else {
                $profileData['profile_photo_url'] = null;
            }
        } else {
            $profileData['profile_photo_url'] = null;
        }

        return response()->json([
            'success' => true,
            'data' => $profileData
        ]);
    }

    /**
     * Mettre à jour le profil
     */
    public function update(Request $request)
    {
        $user = $request->user();
        
        $validator = Validator::make($request->all(), [
            'full_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:20',
            'email' => 'nullable|email|max:255|unique:users,email,' . $user->id,
            'in_game_pseudo' => 'nullable|string|max:255',
            'status' => 'nullable|string|max:255',
            'country' => 'nullable|string|max:100',
            'bio' => 'nullable|string|max:1000',
            'profile_photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $profile = Profile::where('user_id', $user->id)->first();
        
        if (!$profile) {
            $profile = Profile::create([
                'user_id' => $user->id,
                'username' => $user->username,
            ]);
        }

        // Mettre à jour le numéro de téléphone dans la table users
        if ($request->has('phone')) {
            $user->phone = $request->input('phone');
            $user->save();
        }

        // Mettre à jour l'email dans la table users
        if ($request->has('email')) {
            $user->email = $request->input('email');
            $user->save();
        }

        // Mettre à jour les champs du profil (sauf profile_photo qui est géré séparément)
        $updateData = $request->only([
            'full_name', 'in_game_pseudo', 'status', 'country', 'bio'
        ]);
        
        foreach ($updateData as $key => $value) {
            if ($value !== null) {
                $profile->$key = $value;
            }
        }

        // Gérer l'upload de la photo de profil
        if ($request->hasFile('profile_photo')) {
            try {
                // Supprimer l'ancienne photo si elle existe
                if ($profile->profile_photo && Storage::disk('public')->exists($profile->profile_photo)) {
                    Storage::disk('public')->delete($profile->profile_photo);
                }
                
                // Sauvegarder la nouvelle photo
                $path = $request->file('profile_photo')->store('profiles', 'public');
                
                if (!$path) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Erreur lors de l\'enregistrement de la photo'
                    ], 500);
                }
                
                $profile->profile_photo = $path;
                $profile->avatar = $path; // Garder la compatibilité
            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Erreur lors de l\'upload de la photo: ' . $e->getMessage()
                ], 500);
            }
        }

        $profile->save();

        // Régénérer le QR code si nécessaire
        if (!$profile->qr_code || !$profile->profile_url) {
            $this->generateProfileQR($profile);
            $profile->refresh();
        }

        // Recharger l'utilisateur pour avoir les données à jour
        $user->refresh();
        $profile->refresh();
        
        $profileData = $profile->load('user')->toArray();
        
        // Ajouter l'URL complète de la photo de profil
        // Utiliser l'API route pour servir les images directement
        $baseUrl = rtrim(config('app.url'), '/') . '/api';
        
        if ($profile->profile_photo) {
            // Vérifier si le fichier existe
            if (Storage::disk('public')->exists($profile->profile_photo)) {
                // Extraire le nom du fichier depuis le chemin
                $filename = basename($profile->profile_photo);
                $profileData['profile_photo_url'] = $baseUrl . '/storage/profiles/' . $filename;
            } else {
                $profileData['profile_photo_url'] = null;
            }
        } elseif ($profile->avatar) {
            if (Storage::disk('public')->exists($profile->avatar)) {
                $filename = basename($profile->avatar);
                $profileData['profile_photo_url'] = $baseUrl . '/storage/profiles/' . $filename;
            } else {
                $profileData['profile_photo_url'] = null;
            }
        } else {
            $profileData['profile_photo_url'] = null;
        }
        
        return response()->json([
            'success' => true,
            'message' => 'Profil mis à jour avec succès',
            'data' => $profileData
        ]);
    }

    /**
     * Générer le QR code et l'URL du profil
     */
    private function generateProfileQR(Profile $profile)
    {
        $baseUrl = config('app.url', 'http://localhost:5173');
        $profileUrl = $baseUrl . '/profile/' . $profile->user->username;
        
        $profile->profile_url = $profileUrl;
        
        // Générer le QR code via un service en ligne
        $profile->qr_code = 'https://api.qrserver.com/v1/create-qr-code/?size=200x200&data=' . urlencode($profileUrl);
        
        $profile->save();
    }

    /**
     * Obtenir le QR code
     */
    public function getQRCode(Request $request)
    {
        $user = $request->user();
        $profile = Profile::where('user_id', $user->id)->first();
        
        if (!$profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profil non trouvé'
            ], 404);
        }

        // Générer le QR code si nécessaire
        if (!$profile->qr_code) {
            $this->generateProfileQR($profile);
            $profile->refresh();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'qr_code' => $profile->qr_code,
                'profile_url' => $profile->profile_url
            ]
        ]);
    }
}
