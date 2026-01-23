<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\PromoCode;
use App\Models\Referral;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AdminPromoCodeController extends Controller
{
    /**
     * Vérifie et crée les colonnes manquantes pour les promo codes si elles n'existent pas
     */
    private function ensurePromoCodeColumnsExist()
    {
        if (Schema::hasTable('promo_codes')) {
            $columnsToAdd = [
                'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
                'is_welcome_code' => 'TINYINT(1) NOT NULL DEFAULT 0',
                'welcome_bonus' => 'DECIMAL(10,2) NOT NULL DEFAULT 0',
                'first_deposit_bonus_percentage' => 'DECIMAL(5,2) NOT NULL DEFAULT 0',
                'premium_days' => 'INT NOT NULL DEFAULT 0',
                'used_count' => 'INT NOT NULL DEFAULT 0',
                'usage_limit' => 'INT NULL',
                'description' => 'TEXT NULL',
            ];

            foreach ($columnsToAdd as $columnName => $columnDefinition) {
                if (!Schema::hasColumn('promo_codes', $columnName)) {
                    try {
                        DB::statement("ALTER TABLE `promo_codes` ADD COLUMN `{$columnName}` {$columnDefinition}");
                    } catch (\Exception $e) {
                        // Ignorer l'erreur si la colonne existe déjà (cas de race condition)
                        if (strpos($e->getMessage(), 'Duplicate column name') === false) {
                            throw $e;
                        }
                    }
                }
            }
        }
    }
    /**
     * Liste tous les codes promo (codes de bienvenue + codes personnels des utilisateurs)
     */
    public function index(Request $request)
    {
        // S'assurer que les colonnes nécessaires existent
        $this->ensurePromoCodeColumnsExist();
        
        // Récupérer les codes de bienvenue
        $query = PromoCode::query();

        // Filtrer par type si fourni
        if ($request->has('is_welcome_code')) {
            $query->where('is_welcome_code', $request->is_welcome_code);
        }

        // Filtrer par statut actif si fourni
        if ($request->has('is_active')) {
            $query->where('is_active', $request->is_active);
        }

        $welcomeCodes = $query->orderBy('created_at', 'desc')->get();

        // Récupérer les codes promo personnels des utilisateurs
        $userCodesQuery = \App\Models\User::whereNotNull('promo_code')
            ->where('promo_code', '!=', '');

        // Recherche par code si fourni
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $userCodesQuery->where(function($q) use ($search) {
                $q->where('promo_code', 'like', '%' . $search . '%')
                  ->orWhere('username', 'like', '%' . $search . '%')
                  ->orWhere('email', 'like', '%' . $search . '%');
            });
        }

        $userCodes = $userCodesQuery->select('id', 'username', 'email', 'promo_code', 'created_at')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function($user) {
                // Compter les utilisations via la table referrals (parrainage)
                $referralCount = \App\Models\Referral::where('referrer_id', $user->id)->count();
                
                return [
                    'id' => 'user_' . $user->id, // Préfixe pour distinguer des codes de bienvenue
                    'code' => $user->promo_code,
                    'type' => 'user_personal',
                    'is_welcome_code' => false,
                    'is_active' => true,
                    'description' => 'Code promo personnel de ' . $user->username,
                    'user_id' => $user->id,
                    'username' => $user->username,
                    'email' => $user->email,
                    'welcome_bonus' => 0,
                    'first_deposit_bonus_percentage' => 0,
                    'premium_days' => 0,
                    'usage_limit' => null,
                    'used_count' => $referralCount,
                    'expires_at' => null,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ];
            });

        // Combiner les deux types de codes
        $allCodes = $welcomeCodes->concat($userCodes);

        // Trier par date de création
        $allCodes = $allCodes->sortByDesc('created_at')->values();

        return response()->json([
            'success' => true,
            'data' => $allCodes
        ]);
    }

    /**
     * Récupère un code promo spécifique
     */
    public function show($id)
    {
        // Vérifier si c'est un code personnel d'utilisateur
        if (strpos($id, 'user_') === 0) {
            $userId = str_replace('user_', '', $id);
            $user = \App\Models\User::findOrFail($userId);
            
            // Compter les utilisations via la table referrals (parrainage)
            $referralCount = Referral::where('referrer_id', $user->id)->count();
            
            return response()->json([
                'success' => true,
                'data' => [
                    'id' => 'user_' . $user->id,
                    'code' => $user->promo_code,
                    'type' => 'user_personal',
                    'is_welcome_code' => false,
                    'is_active' => true,
                    'description' => 'Code promo personnel de ' . $user->username,
                    'user_id' => $user->id,
                    'username' => $user->username,
                    'email' => $user->email,
                    'welcome_bonus' => 0,
                    'first_deposit_bonus_percentage' => 0,
                    'premium_days' => 0,
                    'usage_limit' => null,
                    'used_count' => $referralCount,
                    'expires_at' => null,
                    'created_at' => $user->created_at,
                    'updated_at' => $user->updated_at,
                ]
            ]);
        }

        $code = PromoCode::findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $code
        ]);
    }

    /**
     * Crée un nouveau code promo
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|max:50|unique:promo_codes,code',
            'description' => 'nullable|string|max:500',
            'welcome_bonus' => 'nullable|numeric|min:0',
            'first_deposit_bonus_percentage' => 'nullable|numeric|min:0|max:100',
            'premium_days' => 'nullable|integer|min:0',
            'is_welcome_code' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'usage_limit' => 'nullable|integer|min:1',
            'expires_at' => 'nullable|date|after:now',
            // Champs de compatibilité
            'amount' => 'nullable|numeric|min:0',
            'bonus' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->only([
            'code',
            'description',
            'welcome_bonus',
            'first_deposit_bonus_percentage',
            'premium_days',
            'is_welcome_code',
            'is_active',
            'usage_limit',
            'expires_at',
            'amount',
            'bonus',
        ]);

        // Set default values
        $data['is_active'] = $request->get('is_active', true);
        $data['is_welcome_code'] = $request->get('is_welcome_code', false);
        $data['welcome_bonus'] = $request->get('welcome_bonus', 0);
        $data['first_deposit_bonus_percentage'] = $request->get('first_deposit_bonus_percentage', 0);
        $data['premium_days'] = $request->get('premium_days', 0);
        $data['usage_limit'] = $request->get('usage_limit', 1);
        $data['used_count'] = 0;

        $code = PromoCode::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Promo code created successfully',
            'data' => $code
        ], 201);
    }

    /**
     * Met à jour un code promo
     */
    public function update(Request $request, $id)
    {
        // Vérifier si c'est un code personnel d'utilisateur
        if (strpos($id, 'user_') === 0) {
            $userId = str_replace('user_', '', $id);
            $user = \App\Models\User::findOrFail($userId);
            
            $validator = Validator::make($request->all(), [
                'code' => 'required|string|max:50|unique:users,promo_code,' . $userId,
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors' => $validator->errors()
                ], 422);
            }

            $user->promo_code = $request->input('code');
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Code promo utilisateur mis à jour avec succès',
                'data' => [
                    'id' => 'user_' . $user->id,
                    'code' => $user->promo_code,
                    'type' => 'user_personal',
                    'username' => $user->username,
                    'email' => $user->email,
                ]
            ]);
        }

        $code = PromoCode::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'code' => 'sometimes|required|string|max:50|unique:promo_codes,code,' . $id,
            'description' => 'nullable|string|max:500',
            'welcome_bonus' => 'nullable|numeric|min:0',
            'first_deposit_bonus_percentage' => 'nullable|numeric|min:0|max:100',
            'premium_days' => 'nullable|integer|min:0',
            'is_welcome_code' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'usage_limit' => 'nullable|integer|min:1',
            'expires_at' => 'nullable|date',
            'amount' => 'nullable|numeric|min:0',
            'bonus' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $data = $request->only([
            'code',
            'description',
            'welcome_bonus',
            'first_deposit_bonus_percentage',
            'premium_days',
            'is_welcome_code',
            'is_active',
            'usage_limit',
            'expires_at',
            'amount',
            'bonus',
        ]);

        $code->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Promo code updated successfully',
            'data' => $code->fresh()
        ]);
    }

    /**
     * Supprime un code promo
     */
    public function destroy($id)
    {
        // Vérifier si c'est un code personnel d'utilisateur
        if (strpos($id, 'user_') === 0) {
            $userId = str_replace('user_', '', $id);
            $user = \App\Models\User::findOrFail($userId);
            
            $user->promo_code = null;
            $user->save();

            return response()->json([
                'success' => true,
                'message' => 'Code promo utilisateur supprimé avec succès'
            ]);
        }

        $code = PromoCode::findOrFail($id);
        $code->delete();

        return response()->json([
            'success' => true,
            'message' => 'Promo code deleted successfully'
        ]);
    }
}

