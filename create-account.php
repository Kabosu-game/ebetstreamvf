<?php

require __DIR__.'/vendor/autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\PromoCode;

echo "=== Création d'un compte avec code promo OLIVERAsWsh ===\n\n";

// Vérifier si le code promo existe
$promoCode = PromoCode::where('code', 'OLIVERAsWsh')->first();

if (!$promoCode) {
    echo "❌ Code promo 'OLIVERAsWsh' introuvable dans la base de données.\n";
    echo "Veuillez d'abord créer ce code promo.\n";
    exit(1);
}

echo "✅ Code promo trouvé:\n";
echo "   - Code: {$promoCode->code}\n";
echo "   - Welcome bonus: $" . number_format($promoCode->welcome_bonus, 2) . "\n";
echo "   - Premium days: {$promoCode->premium_days}\n";
echo "   - First deposit bonus: {$promoCode->first_deposit_bonus_percentage}%\n";
echo "   - Active: " . ($promoCode->is_active ? 'Oui' : 'Non') . "\n\n";

// Générer un username et email unique
$timestamp = time();
$username = "testuser" . $timestamp;
$email = "test{$timestamp}@example.com";
$password = "password123";

echo "Création du compte avec:\n";
echo "   - Username: {$username}\n";
echo "   - Email: {$email}\n";
echo "   - Password: {$password}\n";
echo "   - Promo code: OLIVERAsWsh\n\n";

// Créer la requête pour l'API
$requestData = [
    'username' => $username,
    'email' => $email,
    'password' => $password,
    'password_confirmation' => $password,
    'promo_code' => 'OLIVERAsWsh'
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
        echo "   - Welcome bonus: $" . number_format($responseData['welcome_bonus'], 2) . "\n";
        echo "   - Premium days: {$responseData['premium_days']}\n";
        echo "   - First deposit bonus: {$responseData['first_deposit_bonus_percentage']}%\n";
        echo "   - Token: " . substr($responseData['token'], 0, 20) . "...\n\n";
        
        echo "🔐 Identifiants de connexion:\n";
        echo "   - Email: {$email}\n";
        echo "   - Password: {$password}\n\n";
        
        echo "💡 Le bonus d'inscription sera visible dans l'espace bonus (icône cadeau).\n";
    } else {
        echo "❌ Erreur lors de la création du compte.\n";
        echo "Message: " . ($responseData['message'] ?? 'Erreur inconnue') . "\n";
    }
} catch (\Exception $e) {
    echo "❌ Erreur: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}

