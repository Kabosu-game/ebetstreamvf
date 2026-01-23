<?php

require_once 'vendor/autoload.php';

$app = require_once 'bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Get user by email
$user = \App\Models\User::where('email', 'mtx@gmail.com')->first();

if (!$user) {
    echo "Utilisateur mtx@gmail.com non trouvé\n";
    exit(1);
}

// Get or create wallet
$wallet = \App\Models\Wallet::firstOrCreate(
    ['user_id' => $user->id],
    [
        'balance' => 0,
        'locked_balance' => 0,
        'currency' => 'USD',
    ]
);

// Add 50000 dollars
$wallet->balance += 50000;
$wallet->save();

echo "Compte mtx@gmail.com crédité de 50000 USD avec succès\n";
echo "Nouveau solde: {$wallet->balance} USD\n";
