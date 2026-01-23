<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Wallet;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$emails = ['elie@gmail.com', 'zeed@gmail.com'];
$amount = 94900; // Montant en EBT

echo "=== Crédit de comptes ===\n\n";

DB::beginTransaction();

try {
    foreach ($emails as $email) {
        // Trouver l'utilisateur
        $user = User::where('email', $email)->first();
        
        if (!$user) {
            echo "❌ Utilisateur {$email} non trouvé\n";
            continue;
        }
        
        // Obtenir ou créer le wallet
        $wallet = Wallet::firstOrCreate(
            ['user_id' => $user->id],
            [
                'balance' => 0,
                'locked_balance' => 0,
                'currency' => 'EBT',
            ]
        );
        
        $oldBalance = $wallet->balance;
        
        // Créditer le wallet
        $wallet->balance += $amount;
        $wallet->save();
        
        // Créer une transaction pour l'historique
        $transactionData = [
            'user_id' => $user->id,
            'wallet_id' => $wallet->id,
            'type' => 'deposit',
            'amount' => $amount,
            'status' => 'confirmed',
            'provider' => 'admin_credit',
            'txid' => 'ADMIN_CREDIT_' . $user->id . '_' . now()->format('YmdHis'),
            'description' => 'Crédit administrateur',
        ];
        
        // Ajouter meta si la colonne existe
        if (Schema::hasColumn('transactions', 'meta')) {
            $transactionData['meta'] = json_encode([
                'reason' => 'Admin credit',
                'admin_action' => true,
            ]);
        }
        
        Transaction::create($transactionData);
        
        echo "✅ Compte {$email} crédité de {$amount} EBT avec succès\n";
        echo "   Ancien solde: {$oldBalance} EBT\n";
        echo "   Nouveau solde: {$wallet->balance} EBT\n\n";
    }
    
    DB::commit();
    
    echo "=== Opération terminée avec succès ===\n";
    
} catch (\Exception $e) {
    DB::rollBack();
    echo "❌ Erreur: " . $e->getMessage() . "\n";
    exit(1);
}

