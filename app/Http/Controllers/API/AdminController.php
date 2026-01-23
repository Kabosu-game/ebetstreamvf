<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Challenge;
use App\Models\Stream;
use App\Models\Deposit;
use App\Models\Withdrawal;
use App\Models\Ambassador;
use App\Models\Partner;
use App\Models\CertificationRequest;
use App\Models\Profile;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    /**
     * Vérifie et crée la colonne is_ebetstar si elle n'existe pas
     */
    private function ensureIsEbetstarColumnExists()
    {
        if (Schema::hasTable('users') && !Schema::hasColumn('users', 'is_ebetstar')) {
            try {
                DB::statement('ALTER TABLE `users` ADD COLUMN `is_ebetstar` TINYINT(1) NOT NULL DEFAULT 0');
            } catch (\Exception $e) {
                // Ignorer l'erreur si la colonne existe déjà (cas de race condition)
                if (strpos($e->getMessage(), 'Duplicate column name') === false) {
                    throw $e;
                }
            }
        }
    }

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
     * Récupère les statistiques générales
     */
    public function stats(Request $request)
    {
        $stats = [
            'totalUsers' => User::count(),
            'totalChallenges' => Challenge::count(),
            'totalStreams' => Stream::count(),
            'totalDeposits' => Deposit::where('status', 'approved')->sum('amount'),
            'totalWithdrawals' => Withdrawal::where('status', 'approved')->sum('amount'),
            'totalAmbassadors' => Ambassador::where('is_active', true)->count(),
            'totalPartners' => Partner::where('is_active', true)->count(),
        ];

        return response()->json([
            'success' => true,
            'data' => $stats
        ]);
    }

    /**
     * Liste tous les utilisateurs
     */
    public function users(Request $request)
    {
        // S'assurer que la colonne is_ebetstar existe
        $this->ensureIsEbetstarColumnExists();
        
        $query = User::with(['wallet', 'profile']);
        
        // Recherche par nom, email ou username
        if ($request->has('search') && !empty($request->get('search'))) {
            $search = $request->get('search');
            $query->where(function($q) use ($search) {
                $q->where('email', 'like', "%{$search}%")
                  ->orWhere('username', 'like', "%{$search}%")
                  ->orWhereHas('profile', function($profileQuery) use ($search) {
                      $profileQuery->where('full_name', 'like', "%{$search}%");
                  });
            });
        }
        
        // Filtre par rôle
        if ($request->has('role') && !empty($request->get('role'))) {
            $query->where('role', $request->get('role'));
        }
        
        $users = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        // Ajouter le solde de chaque utilisateur
        $users->getCollection()->transform(function ($user) {
            $user->balance = $user->wallet ? $user->wallet->balance : 0;
            $user->role = $user->role ?? 'player';
            return $user;
        });

        return response()->json([
            'success' => true,
            'data' => $users
        ]);
    }

    /**
     * Crée un nouvel utilisateur
     */
    public function createUser(Request $request)
    {
        // S'assurer que la colonne is_ebetstar existe
        $this->ensureIsEbetstarColumnExists();
        
        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'required|email|unique:users,email',
            'username' => 'nullable|string|max:255|unique:users,username',
            'password' => 'required|string|min:8',
            'role' => 'nullable|string|in:user,admin',
            'is_ebetstar' => 'nullable|boolean',
        ]);

        $user = User::create([
            'email' => $validated['email'],
            'username' => $validated['username'] ?? null,
            'password' => Hash::make($validated['password']),
            'is_ebetstar' => $validated['is_ebetstar'] ?? false,
        ]);

        // Créer le profil si un nom est fourni
        if (isset($validated['name'])) {
            $user->profile()->create([
                'full_name' => $validated['name'],
            ]);
        }

        // Créer le wallet (si n'existe pas déjà)
        $user->wallet()->firstOrCreate(
            ['user_id' => $user->id],
            [
                'balance' => 0,
                'locked_balance' => 0,
                'currency' => 'USD',
            ]
        );

        return response()->json([
            'success' => true,
            'message' => 'Utilisateur créé avec succès',
            'data' => $user->load(['wallet', 'profile'])
        ], 201);
    }

    /**
     * Met à jour un utilisateur
     */
    public function updateUser(Request $request, $id)
    {
        // S'assurer que la colonne is_ebetstar existe
        $this->ensureIsEbetstarColumnExists();
        
        $user = User::findOrFail($id);

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'email' => 'sometimes|email|unique:users,email,' . $id,
            'username' => 'nullable|string|max:255|unique:users,username,' . $id,
            'password' => 'nullable|string|min:8',
            'role' => 'nullable|string|in:user,admin',
            'is_ebetstar' => 'nullable|boolean',
        ]);

        if (isset($validated['email'])) {
            $user->email = $validated['email'];
        }
        if (isset($validated['username'])) {
            $user->username = $validated['username'];
        }
        if (isset($validated['password'])) {
            $user->password = Hash::make($validated['password']);
        }
        if (isset($validated['is_ebetstar'])) {
            $user->is_ebetstar = $validated['is_ebetstar'];
        }
        $user->save();

        // Mettre à jour le profil
        if (isset($validated['name'])) {
            $profile = $user->profile;
            if ($profile) {
                $profile->full_name = $validated['name'];
                $profile->save();
            } else {
                $user->profile()->create([
                    'full_name' => $validated['name'],
                ]);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Utilisateur mis à jour avec succès',
            'data' => $user->load(['wallet', 'profile'])
        ]);
    }

    /**
     * Supprime un utilisateur
     */
    public function deleteUser($id)
    {
        $user = User::findOrFail($id);
        $user->delete();

        return response()->json([
            'success' => true,
            'message' => 'Utilisateur supprimé avec succès'
        ]);
    }

    /**
     * Modifie le solde d'un utilisateur (ajouter ou retirer)
     */
    public function updateUserBalance(Request $request, $id)
    {
        $user = User::findOrFail($id);
        
        $validated = $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'type' => 'required|in:add,subtract',
            'reason' => 'nullable|string|max:500',
        ]);

        $wallet = $user->wallet;
        if (!$wallet) {
            $wallet = $user->wallet()->create([
                'balance' => 0,
                'currency' => 'USD',
            ]);
        }

        $amount = $validated['amount'];
        $type = $validated['type'];
        $oldBalance = $wallet->balance;

        if ($type === 'add') {
            $wallet->balance += $amount;
            $newBalance = $wallet->balance;
        } else {
            // Vérifier que le solde est suffisant
            if ($wallet->balance < $amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'Le solde de l\'utilisateur est insuffisant. Solde actuel: $' . number_format($wallet->balance, 2)
                ], 400);
            }
            $wallet->balance -= $amount;
            
            // Protection supplémentaire : s'assurer que le solde ne devient jamais négatif
            if ($wallet->balance < 0) {
                $wallet->balance = 0;
            }
            
            $newBalance = $wallet->balance;
        }

        $wallet->save();

        // Créer une transaction pour l'historique
        $description = $validated['reason'] ?? ($type === 'add' ? 'Ajout de fonds par l\'administrateur' : 'Retrait de fonds par l\'administrateur');
        $user->transactions()->create([
            'wallet_id' => $wallet->id,
            'type' => $type === 'add' ? 'deposit' : 'withdraw',
            'amount' => $amount,
            'status' => 'confirmed',
            'provider' => 'admin',
            'txid' => 'ADMIN_' . $type . '_' . now()->format('YmdHis') . '_' . $user->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => $type === 'add' 
                ? 'Montant ajouté avec succès' 
                : 'Montant retiré avec succès',
            'data' => [
                'user_id' => $user->id,
                'old_balance' => $oldBalance,
                'new_balance' => $newBalance,
                'amount' => $amount,
                'type' => $type,
            ]
        ]);
    }

    /**
     * Liste tous les dépôts
     */
    public function deposits(Request $request)
    {
        $deposits = Deposit::with('user')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $deposits
        ]);
    }

    /**
     * Approuve un dépôt
     */
    public function approveDeposit(Request $request, $id)
    {
        // S'assurer que les colonnes nécessaires existent
        $this->ensureWelcomeCodeColumnsExist();
        
        $deposit = Deposit::findOrFail($id);
        
        if ($deposit->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Ce dépôt ne peut pas être approuvé'
            ], 400);
        }

        $deposit->status = 'approved';
        $deposit->save();

        // Créer le wallet s'il n'existe pas
        $wallet = $deposit->user->wallet;
        if (!$wallet) {
            $wallet = $deposit->user->wallet()->create([
                'balance' => 0,
                'locked_balance' => 0,
                'currency' => 'EBT',
            ]);
        }

        // Convertir le montant du dépôt de dollars en EBT (1$ = 100 EBT)
        // Le montant du dépôt est en dollars, on le convertit en EBT
        $amountInEBT = $deposit->amount * 100;
        $wallet->balance += $amountInEBT;
        
        // Vérifier et appliquer le bonus sur la première recharge
        $bonusAmount = 0;
        if (!$deposit->user->first_deposit_bonus_applied && $deposit->user->used_welcome_code) {
            $promoCode = \App\Models\PromoCode::where('code', $deposit->user->used_welcome_code)->first();
            
            if ($promoCode && $promoCode->first_deposit_bonus_percentage > 0) {
                // Le bonus est calculé en dollars, puis converti en EBT
                $bonusAmountInDollars = ($deposit->amount * $promoCode->first_deposit_bonus_percentage) / 100;
                $bonusAmount = $bonusAmountInDollars * 100; // Convertir en EBT (1$ = 100 EBT)
                
                // Ne pas créditer le bonus directement dans la balance
                // Le bonus sera stocké dans l'espace bonus avec status 'locked'
                // Il sera disponible après avoir rempli les conditions de retrait
                
                // Marquer que le bonus a été appliqué
                $deposit->user->first_deposit_bonus_applied = true;
                $deposit->user->save();
                
                // Créer une transaction pour le bonus avec status 'locked'
                // Le bonus ne sera pas crédité dans wallet.balance mais stocké dans l'espace bonus
                $deposit->user->transactions()->create([
                    'wallet_id' => $wallet->id,
                    'user_id' => $deposit->user->id,
                    'type' => 'deposit',
                    'amount' => $bonusAmount, // Montant en EBT
                    'status' => 'locked', // Status locked = bonus non retirable, soumis à conditions
                    'provider' => 'first_deposit_bonus',
                    'txid' => 'FIRST_DEPOSIT_BONUS_' . $deposit->user->id . '_' . now()->format('YmdHis'),
                ]);
            }
        }
        
        $wallet->save();
        
        // Créer une transaction pour le dépôt (montant en EBT)
        $deposit->user->transactions()->create([
            'wallet_id' => $wallet->id,
            'user_id' => $deposit->user->id,
            'type' => 'deposit',
            'amount' => $amountInEBT, // Montant en EBT
            'status' => 'confirmed',
            'provider' => $deposit->method,
            'txid' => 'DEPOSIT_' . $deposit->id . '_' . now()->format('YmdHis'),
        ]);

        $message = 'Dépôt approuvé avec succès';
        if ($bonusAmount > 0) {
            $bonusAmountInDollars = $bonusAmount / 100; // Convertir EBT en dollars pour l'affichage
            $message .= '. Bonus de première recharge de $' . number_format($bonusAmountInDollars, 2) . ' (' . number_format($bonusAmount, 0) . ' EBT) ajouté dans l\'espace bonus (conditions de retrait à remplir).';
        }

        return response()->json([
            'success' => true,
            'message' => $message,
            'bonus_applied' => $bonusAmount > 0,
            'bonus_amount' => $bonusAmount,
        ]);
    }

    /**
     * Rejette un dépôt
     */
    public function rejectDeposit(Request $request, $id)
    {
        $deposit = Deposit::findOrFail($id);
        
        if ($deposit->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Ce dépôt ne peut pas être rejeté'
            ], 400);
        }

        $deposit->status = 'rejected';
        $deposit->save();

        return response()->json([
            'success' => true,
            'message' => 'Dépôt rejeté'
        ]);
    }

    /**
     * Liste tous les retraits
     */
    public function withdrawals(Request $request)
    {
        $withdrawals = Withdrawal::with('user')
            ->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $withdrawals
        ]);
    }

    /**
     * Approuve un retrait
     */
    public function approveWithdrawal(Request $request, $id)
    {
        $withdrawal = Withdrawal::findOrFail($id);
        
        if ($withdrawal->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Ce retrait ne peut pas être approuvé'
            ], 400);
        }

        $withdrawal->status = 'approved';
        $withdrawal->save();

        // Le montant est déjà déduit du wallet lors de la création du retrait

        return response()->json([
            'success' => true,
            'message' => 'Retrait approuvé avec succès'
        ]);
    }

    /**
     * Rejette un retrait
     */
    public function rejectWithdrawal(Request $request, $id)
    {
        $withdrawal = Withdrawal::findOrFail($id);
        
        if ($withdrawal->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Ce retrait ne peut pas être rejeté'
            ], 400);
        }

        // Remettre le montant dans le wallet
        $wallet = $withdrawal->user->wallet;
        if ($wallet) {
            $wallet->balance += $withdrawal->amount;
            $wallet->locked_balance -= $withdrawal->amount;
            
            // Protection : s'assurer que locked_balance ne devient jamais négatif
            if ($wallet->locked_balance < 0) {
                $wallet->locked_balance = 0;
            }
            
            $wallet->save();
        }

        $withdrawal->status = 'rejected';
        $withdrawal->save();

        return response()->json([
            'success' => true,
            'message' => 'Retrait rejeté'
        ]);
    }

    /**
     * Liste toutes les demandes de certification
     */
    public function certifications(Request $request)
    {
        $status = $request->get('status');
        $query = CertificationRequest::with(['user.profile', 'reviewer']);

        if ($status) {
            $query->where('status', $status);
        }

        $certifications = $query->orderBy('created_at', 'desc')
            ->paginate($request->get('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $certifications
        ]);
    }

    /**
     * Approuve une demande de certification
     */
    public function approveCertification(Request $request, $id)
    {
        $certificationRequest = CertificationRequest::with('user.profile')->findOrFail($id);
        
        if ($certificationRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Cette demande ne peut pas être approuvée'
            ], 400);
        }

        $admin = $request->user();
        
        $certificationRequest->status = 'approved';
        $certificationRequest->reviewed_by = $admin->id;
        $certificationRequest->reviewed_at = now();
        $certificationRequest->save();

        // Ajouter la certification au profil de l'utilisateur
        $profile = $certificationRequest->user->profile;
        if ($profile) {
            $certifications = $profile->certifications ?? [];
            if (!in_array('Ebetstream', $certifications)) {
                $certifications[] = 'Ebetstream';
                $profile->certifications = $certifications;
                $profile->save();
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Certification approuvée avec succès',
            'data' => $certificationRequest->load(['user.profile', 'reviewer'])
        ]);
    }

    /**
     * Rejette une demande de certification
     */
    public function rejectCertification(Request $request, $id)
    {
        $certificationRequest = CertificationRequest::findOrFail($id);
        
        if ($certificationRequest->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Cette demande ne peut pas être rejetée'
            ], 400);
        }

        $validated = $request->validate([
            'rejection_reason' => 'required|string|max:1000',
        ]);

        $admin = $request->user();
        
        $certificationRequest->status = 'rejected';
        $certificationRequest->reviewed_by = $admin->id;
        $certificationRequest->reviewed_at = now();
        $certificationRequest->rejection_reason = $validated['rejection_reason'];
        $certificationRequest->save();

        return response()->json([
            'success' => true,
            'message' => 'Certification rejetée',
            'data' => $certificationRequest->load(['user.profile', 'reviewer'])
        ]);
    }

    /**
     * Obtient les détails d'une demande de certification
     */
    public function getCertification($id)
    {
        $certificationRequest = CertificationRequest::with(['user.profile', 'reviewer'])
            ->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $certificationRequest
        ]);
    }
}
