<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\User;
use App\Models\Wallet;
use App\Models\PromoCode;
use App\Models\Transaction;
use App\Models\Referral;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class RegisterController extends Controller
{
    /**
     * Vérifie et crée les colonnes manquantes pour les codes de bienvenue si elles n'existent pas
     */
    private function ensureWelcomeCodeColumnsExist()
    {
        if (Schema::hasTable('users')) {
            $columnsToAdd = [
                'used_welcome_code' => 'VARCHAR(255) NULL',
                'premium_until' => 'DATETIME NULL',
                'first_deposit_bonus_applied' => 'TINYINT(1) NOT NULL DEFAULT 0',
            ];

            foreach ($columnsToAdd as $columnName => $columnDefinition) {
                if (!Schema::hasColumn('users', $columnName)) {
                    try {
                        DB::statement("ALTER TABLE `users` ADD COLUMN `{$columnName}` {$columnDefinition}");
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
    public function register(Request $request)
    {
        // S'assurer que les colonnes nécessaires existent
        $this->ensureWelcomeCodeColumnsExist();
        $this->ensurePromoCodeColumnsExist();
        
        // Validation
        // Note: 'promo_code' ici est le code de bienvenue entré par l'utilisateur lors de l'inscription
        // Le code promo personnel de l'utilisateur sera généré automatiquement par UserObserver
        $validated = $request->validate([
            'username'   => 'required|string|max:50|unique:users,username',
            'email'      => 'required|email|unique:users,email',
            'password'   => 'required|string|min:6|confirmed',
            'promo_code' => 'nullable|string|max:50', // Code de bienvenue (welcome code) entré par l'utilisateur
        ]);

        $welcomeBonus = 0;
        $premiumDays = 0;
        $promoCodeModel = null;
        $firstDepositBonusPercentage = 0;
        $referrer = null; // Le parrain (utilisateur qui possède le code promo utilisé)

        // Vérifier et appliquer le code promo
        if (!empty($validated['promo_code'])) {
            // D'abord, vérifier si c'est un code promo de bienvenue (PromoCode)
            $promoCodeModel = PromoCode::where('code', $validated['promo_code'])
                ->where('is_active', true)
                ->first();

            if ($promoCodeModel && $promoCodeModel->canBeUsed()) {
                // C'est un code de bienvenue
                $welcomeBonus = $promoCodeModel->welcome_bonus ?? 0;
                $premiumDays = $promoCodeModel->premium_days ?? 0;
                $firstDepositBonusPercentage = $promoCodeModel->first_deposit_bonus_percentage ?? 0;

                // Incrémenter le compteur d'utilisation
                $promoCodeModel->incrementUsage();
            } else {
                // Sinon, vérifier si c'est un code promo personnel d'un utilisateur (parrainage)
                $referrer = User::where('promo_code', $validated['promo_code'])->first();
                
                if ($referrer) {
                    // C'est un code de parrainage - on appliquera les bonus après la création de l'utilisateur
                }
            }
        }

        // Calculer la date d'expiration premium
        $premiumUntil = null;
        if ($premiumDays > 0) {
            $premiumUntil = Carbon::now()->addDays($premiumDays);
        }

        // Création de l'utilisateur
        // Note: Ne pas définir 'promo_code' ici car il sera généré automatiquement par UserObserver
        $user = User::create([
            'username'   => $validated['username'],
            'email'      => $validated['email'],
            'password'   => Hash::make($validated['password']),
            // 'promo_code' sera généré automatiquement par UserObserver
            'used_welcome_code' => $promoCodeModel ? $promoCodeModel->code : null,
            'premium_until' => $premiumUntil,
            'first_deposit_bonus_applied' => false,
        ]);
        
        // Recharger l'utilisateur pour avoir le promo_code généré par l'observer
        // et le wallet créé par l'observer
        $user->refresh();

        // Récupérer le wallet créé par l'observer
        $wallet = $user->wallet;

        // Créer une transaction pour le bonus de bienvenue avec status 'locked'
        // Le bonus ne sera pas crédité dans la balance principale mais stocké dans l'espace bonus
        // Il sera disponible après avoir rempli les conditions de retrait
        if ($welcomeBonus > 0 && $wallet) {
            $user->transactions()->create([
                'wallet_id' => $wallet->id,
                'type' => 'deposit',
                'amount' => $welcomeBonus,
                'status' => 'locked', // Status locked = bonus non retirable, soumis à conditions
                'provider' => 'welcome_bonus',
                'txid' => 'WELCOME_' . $user->id . '_' . now()->format('YmdHis'),
            ]);
            // Note: On ne crédite PAS le wallet->balance ici
            // Le bonus sera affiché dans l'espace bonus et crédité uniquement après avoir rempli les conditions
        }

        // Gérer le système de parrainage si un code promo personnel a été utilisé
        if ($referrer && $referrer->id !== $user->id) {
            // Montants de bonus pour le parrainage (configurables)
            $referrerBonus = 10.00; // Bonus pour le parrain
            $refereeBonus = 5.00;   // Bonus pour le filleul

            // Vérifier qu'il n'y a pas déjà un referral existant
            $existingReferral = Referral::where('referrer_id', $referrer->id)
                ->where('referred_id', $user->id)
                ->first();

            if (!$existingReferral) {
                // Créer l'entrée de referral
                $referral = Referral::create([
                    'referrer_id' => $referrer->id,
                    'referred_id' => $user->id,
                    'bonus' => $referrerBonus,
                ]);

                // Distribuer le bonus au parrain (dans l'espace bonus avec status 'locked')
                $referrerWallet = $referrer->wallet;
                if ($referrerWallet) {
                    // Ne pas créditer directement dans la balance, créer une transaction 'locked'
                    $referrer->transactions()->create([
                        'wallet_id' => $referrerWallet->id,
                        'user_id' => $referrer->id,
                        'type' => 'deposit',
                        'amount' => $referrerBonus,
                        'status' => 'locked', // Bonus dans l'espace bonus, soumis à conditions
                        'provider' => 'referral_bonus',
                        'txid' => 'REFERRER_' . $referrer->id . '_' . $user->id . '_' . now()->format('YmdHis'),
                    ]);
                }

                // Distribuer le bonus au filleul (dans l'espace bonus avec status 'locked')
                if ($wallet && $refereeBonus > 0) {
                    // Ne pas créditer directement dans la balance, créer une transaction 'locked'
                    $user->transactions()->create([
                        'wallet_id' => $wallet->id,
                        'user_id' => $user->id,
                        'type' => 'deposit',
                        'amount' => $refereeBonus,
                        'status' => 'locked', // Bonus dans l'espace bonus, soumis à conditions
                        'provider' => 'referral_bonus',
                        'txid' => 'REFEREE_' . $user->id . '_' . $referrer->id . '_' . now()->format('YmdHis'),
                    ]);
                }
            }
        }

        // Création du token Sanctum
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'User registered successfully',
            'user'    => $user,
            'token'   => $token,
            'welcome_bonus' => $welcomeBonus,
            'premium_days' => $premiumDays,
            'first_deposit_bonus_percentage' => $firstDepositBonusPercentage,
        ]);
    }
}
