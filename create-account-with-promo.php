<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\User;
use App\Models\PromoCode;

echo "=== Création d'un compte avec code promo d'olivera@gmail.com ===\n\n";

// Trouver l'utilisateur olivera@gmail.com
$referrerUser = User::where('email', 'olivera@gmail.com')->first();

if (!$referrerUser) {
    echo "❌ Utilisateur 'olivera@gmail.com' introuvable dans la base de données.\n";
    exit(1);
}

$promoCode = $referrerUser->promo_code;

if (!$promoCode) {
    echo "❌ L'utilisateur 'olivera@gmail.com' n'a pas de code promo personnel.\n";
    echo "   Username: {$referrerUser->username}\n";
    echo "   ID: {$referrerUser->id}\n";
    exit(1);
}

echo "✅ Utilisateur trouvé:\n";
echo "   - Username: {$referrerUser->username}\n";
echo "   - Email: {$referrerUser->email}\n";
echo "   - ID: {$referrerUser->id}\n";
echo "   - Code promo personnel: {$promoCode}\n\n";

// Générer un username et email unique
$timestamp = time();
$username = "testuser" . $timestamp;
$email = "test{$timestamp}@example.com";
$password = "password123";

echo "Création du compte avec:\n";
echo "   - Username: {$username}\n";
echo "   - Email: {$email}\n";
echo "   - Password: {$password}\n";
echo "   - Promo code (parrainage): {$promoCode}\n\n";

// Créer la requête pour l'API
$requestData = [
    'username' => $username,
    'email' => $email,
    'password' => $password,
    'password_confirmation' => $password,
    'promo_code' => $promoCode
];

// Créer une requête HTTP simulée
$request = \Illuminate\Http\Request::create('/api/register', 'POST', $requestData);

// Obtenir l'instance du controller
$controller = new \App\Http\Controllers\API\RegisterController();

// Appeler la méthode register
try {
    $response = $controller->register($request);
    $responseData = json_decode($response->getContent(), true);
    
    if ($responseData['success']) {
        echo "✅ Compte créé avec succès !\n\n";
        echo "Détails du compte:\n";
        echo "   - ID: {$responseData['user']['id']}\n";
        echo "   - Username: {$responseData['user']['username']}\n";
        echo "   - Email: {$responseData['user']['email']}\n";
        echo "   - Promo code personnel: {$responseData['user']['promo_code']}\n";
        echo "   - Parrain: {$referrerUser->username} (ID: {$referrerUser->id})\n";
        echo "   - Code promo utilisé: {$promoCode}\n";
        $welcomeBonus = isset($responseData['welcome_bonus']) ? $responseData['welcome_bonus'] : 0;
        $premiumDays = isset($responseData['premium_days']) ? $responseData['premium_days'] : 0;
        echo "   - Welcome bonus: $" . number_format($welcomeBonus, 2) . "\n";
        echo "   - Premium days: {$premiumDays}\n";
        echo "   - Token: " . substr($responseData['token'], 0, 20) . "...\n\n";
        
        echo "🔐 Identifiants de connexion:\n";
        echo "   - Email: {$email}\n";
        echo "   - Password: {$password}\n\n";
        
        echo "💡 Avec le système de parrainage:\n";
        echo "   - Le parrain ({$referrerUser->username}) recevra un bonus de $10.00\n";
        echo "   - Le filleul (vous) recevra un bonus de $5.00\n";
        echo "   - Ces bonus seront crédités directement dans la balance principale.\n";
    } else {
        echo "❌ Erreur lors de la création du compte.\n";
        if (isset($responseData['errors'])) {
            foreach ($responseData['errors'] as $field => $errors) {
                foreach ($errors as $error) {
                    echo "   - {$field}: {$error}\n";
                }
            }
        } else {
            echo "Message: " . ($responseData['message'] ?? 'Erreur inconnue') . "\n";
        }
    }
} catch (\Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
    if (strpos($e->getMessage(), 'SQLSTATE') !== false || strpos($e->getMessage(), 'Duplicate') !== false) {
        echo "   → Erreur de base de données (email ou username déjà utilisé)\n";
    }
    exit(1);
}

