<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\Deposit;
use App\Models\PromoCode;
use App\Models\Transaction;
use App\Models\Wallet;

class DepositController extends Controller
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
     * Store a new deposit.
     */
    public function store(Request $request)
    {
        // S'assurer que les colonnes nécessaires existent
        $this->ensureWelcomeCodeColumnsExist();
        
        $user = $request->user();

        // Validation selon le type de dépôt
        // Note: Le montant est en dollars, mais sera converti en EBT lors de l'approbation (1$ = 100 EBT)
        $rules = [
            'deposit_method' => 'required|in:crypto,cash',
            'amount' => 'required|numeric|min:0.01', // N'importe quelle somme en dollars
        ];

        // Récupérer la méthode de dépôt
        $depositMethod = $request->input('deposit_method');

        if ($depositMethod === 'crypto') {
            $rules['crypto_name'] = 'required|string';
            $rules['transaction_hash'] = 'required|string';
        } elseif ($depositMethod === 'cash') {
            $rules['location'] = 'required|string';
        }

        $validator = Validator::make($request->all(), $rules);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        $amount = $request->input('amount');
        $bonusAmount = 0;

        // Vérifier si c'est la première recharge et appliquer le bonus
        if (!$user->first_deposit_bonus_applied && $user->used_welcome_code) {
            $promoCode = PromoCode::where('code', $user->used_welcome_code)->first();
            
            if ($promoCode && $promoCode->first_deposit_bonus_percentage > 0) {
                $bonusAmount = ($amount * $promoCode->first_deposit_bonus_percentage) / 100;
                
                // Marquer que le bonus a été appliqué
                $user->first_deposit_bonus_applied = true;
                $user->save();
            }
        }

        // Création du dépôt
        $deposit = Deposit::create([
            'user_id' => $user->id,
            'method' => $depositMethod,
            'amount' => $amount,
            'crypto_name' => $request->input('crypto_name'),
            'transaction_hash' => $request->input('transaction_hash'),
            'location' => $request->input('location'),
            'status' => 'pending',
        ]);

        // Si le dépôt est approuvé automatiquement (ou si vous voulez l'approuver immédiatement)
        // Note: Normalement, l'approbation se fait via l'admin, mais on peut préparer le bonus
        $response = [
            'success' => true,
            'message' => 'Deposit submitted successfully!',
            'data' => $deposit
        ];

        if ($bonusAmount > 0) {
            $response['bonus_info'] = [
                'bonus_amount' => $bonusAmount,
                'bonus_percentage' => $promoCode->first_deposit_bonus_percentage,
                'message' => 'Un bonus de ' . number_format($bonusAmount, 2) . '$ sera ajouté après approbation de votre dépôt.'
            ];
        }

        return response()->json($response, 201);
    }

    /**
     * Get user's deposit history
     */
    public function index(Request $request)
    {
        $user = $request->user();
        
        $deposits = Deposit::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'data' => $deposits
        ]);
    }

    /**
     * Get a specific deposit
     */
    public function show(Request $request, $id)
    {
        $user = $request->user();
        
        $deposit = Deposit::where('user_id', $user->id)
            ->where('id', $id)
            ->first();

        if (!$deposit) {
            return response()->json([
                'success' => false,
                'message' => 'Deposit not found'
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $deposit
        ]);
    }
}