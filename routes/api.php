<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\API\RegisterController;
use App\Http\Controllers\API\AuthController;
use App\Http\Controllers\API\RechargeAgentController;
use App\Http\Controllers\API\ChallengeController;
use App\Http\Controllers\API\StreamController;
use App\Http\Controllers\API\ProfileController;
use App\Http\Controllers\API\EbetStarController;
use App\Http\Controllers\API\AmbassadorController;
use App\Http\Controllers\API\TopPlayersController;
use App\Http\Controllers\API\PartnerController;
use App\Http\Controllers\API\AdminController;
use App\Http\Controllers\API\BetController;
use App\Http\Controllers\API\CertificationController;
use App\Http\Controllers\API\EventController;
use App\Http\Controllers\API\AdminPaymentMethodController;
use App\Http\Controllers\API\AdminPromoCodeController;
use App\Http\Controllers\API\AdminBetController;
use App\Http\Controllers\API\AdminChallengeController;
use App\Http\Controllers\API\ClanController;
use App\Http\Controllers\API\CertificationRequestController;
use App\Http\Controllers\API\AdminCertificationRequestController;
use App\Http\Controllers\API\AgentRequestController;
use App\Http\Controllers\API\AdminAgentRequestController;
use App\Http\Controllers\API\WithdrawalCodeController;
use App\Http\Controllers\API\FederationController;
use App\Http\Controllers\API\BallonDorController;
use App\Http\Controllers\API\AdminFederationController;
use App\Http\Controllers\API\AdminBallonDorController;
use App\Http\Controllers\API\AdminMonetizationController;
use App\Http\Controllers\API\DonationController;
use App\Http\Controllers\API\StreamPredictionController;
use App\Http\Controllers\API\SponsoredMatchController;
use App\Http\Controllers\API\TeamMarketplaceController;
use App\Http\Controllers\API\TournamentController;
use App\Http\Controllers\API\ChampionshipController;
use App\Http\Controllers\API\AdminChampionshipController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\WithdrawalController;
use App\Http\Controllers\API\TokenVerifyController;

// =============================================================================
// ROUTE DE TEST
// =============================================================================
Route::get('/test', function () {
    return response()->json([
        'success' => true,
        'message' => 'API is working!'
    ]);
});

// =============================================================================
// VÉRIFICATION DU TOKEN (auth:sanctum uniquement)
// =============================================================================
Route::middleware('auth:sanctum')->get('/token/verify', [TokenVerifyController::class, 'verify']);

// =============================================================================
// ROUTE INTERNE (serveur Node.js WebSocket)
// =============================================================================
Route::middleware(['internal.token'])->group(function () {
    Route::get('/internal/stream-key/{id}', [StreamController::class, 'internalGetStreamKey']);
});

// =============================================================================
// DEPLOY CHECK
// =============================================================================
Route::get('/deploy-check', function () {
    return response()->json([
        'ok' => true,
        'federations_route' => true,
        'api_routes_file' => file_exists(base_path('routes/api.php')) ? 'exists' : 'missing',
    ]);
});

// =============================================================================
// VIDAGE DES CACHES (protégé par token)
// GET ou POST /api/clear-cache?token=XXX  ou  Header: X-Clear-Cache-Token: XXX
// Définir CLEAR_CACHE_TOKEN dans .env sur le serveur.
// =============================================================================
Route::match(['get', 'post'], '/clear-cache', function (Request $request) {
    $token = $request->header('X-Clear-Cache-Token') ?: $request->query('token');
    $expected = env('CLEAR_CACHE_TOKEN');

    if (empty($expected)) {
        return response()->json([
            'success' => false,
            'message' => 'Clear-cache not configured (set CLEAR_CACHE_TOKEN in .env).',
        ], 503);
    }

    if (!hash_equals((string) $expected, (string) $token)) {
        return response()->json([
            'success' => false,
            'message' => 'Invalid or missing token.',
        ], 403);
    }

    $commands = ['config:clear', 'cache:clear', 'route:clear', 'view:clear', 'optimize:clear'];
    $results = [];

    foreach ($commands as $command) {
        $output = [];
        $returnVar = -1;
        exec('php ' . base_path('artisan') . ' ' . $command . ' 2>&1', $output, $returnVar);
        $results[$command] = [
            'ok' => $returnVar === 0,
            'output' => implode("\n", $output),
        ];
    }

    $allOk = !in_array(false, array_column($results, 'ok'), true);

    return response()->json([
        'success' => $allOk,
        'message' => $allOk ? 'All caches cleared.' : 'Some commands failed.',
        'results' => $results,
    ], $allOk ? 200 : 500);
});

// =============================================================================
// ROUTES DE STOCKAGE (fichiers publics)
// =============================================================================

// Profils
Route::get('/storage/profiles/{filename}', function ($filename) {
    $path = storage_path('app/public/profiles/' . $filename);
    if (!file_exists($path)) return response()->json(['error' => 'File not found'], 404);
    return response(file_get_contents($path), 200)
        ->header('Content-Type', mime_content_type($path))
        ->header('Cache-Control', 'public, max-age=31536000');
});

// Ambassadeurs
Route::get('/storage/ambassadors/{filename}', function ($filename) {
    $path = storage_path('app/public/ambassadors/' . $filename);
    if (!file_exists($path)) return response()->json(['error' => 'File not found'], 404);
    return response(file_get_contents($path), 200)
        ->header('Content-Type', mime_content_type($path))
        ->header('Cache-Control', 'public, max-age=31536000');
});

// Partenaires
Route::get('/storage/partners/{filename}', function ($filename) {
    $path = storage_path('app/public/partners/' . $filename);
    if (!file_exists($path)) return response()->json(['error' => 'File not found'], 404);
    return response(file_get_contents($path), 200)
        ->header('Content-Type', mime_content_type($path))
        ->header('Cache-Control', 'public, max-age=31536000');
});

// Événements
Route::get('/storage/events/{filename}', function ($filename) {
    $path = storage_path('app/public/events/' . $filename);
    if (!file_exists($path)) return response()->json(['error' => 'File not found'], 404);
    return response(file_get_contents($path), 200)
        ->header('Content-Type', mime_content_type($path))
        ->header('Cache-Control', 'public, max-age=31536000');
});

// Streams thumbnails
Route::get('/storage/streams/thumbnails/{filename}', function ($filename) {
    $path = storage_path('app/public/streams/thumbnails/' . $filename);
    if (!file_exists($path)) return response()->json(['error' => 'File not found'], 404);
    return response(file_get_contents($path), 200)
        ->header('Content-Type', mime_content_type($path))
        ->header('Cache-Control', 'public, max-age=31536000');
});

// Certifications
Route::get('/storage/certifications/{folder}/{filename}', function ($folder, $filename) {
    $path = storage_path('app/public/certifications/' . $folder . '/' . $filename);
    if (!file_exists($path)) return response()->json(['error' => 'File not found'], 404);
    return response(file_get_contents($path), 200)
        ->header('Content-Type', mime_content_type($path))
        ->header('Cache-Control', 'public, max-age=31536000');
});

// Jeux (sous-dossiers)
Route::get('/storage/games/{subfolder}/{filename}', function ($subfolder, $filename) {
    $allowedSubfolders = ['icons', 'images'];
    if (!in_array($subfolder, $allowedSubfolders)) return response()->json(['error' => 'Access denied'], 403);
    $path = storage_path('app/public/games/' . $subfolder . '/' . $filename);
    if (!file_exists($path)) return response()->json(['error' => 'File not found'], 404);
    return response(file_get_contents($path), 200)
        ->header('Content-Type', mime_content_type($path))
        ->header('Cache-Control', 'public, max-age=31536000');
});

// Route générique (dossiers autorisés)
Route::get('/storage/{folder}/{filename}', function ($folder, $filename) {
    $allowedFolders = ['ambassadors', 'partners', 'profiles', 'events', 'streams', 'certifications', 'games', 'categories'];
    if (!in_array($folder, $allowedFolders)) return response()->json(['error' => 'Access denied'], 403);
    $path = storage_path('app/public/' . $folder . '/' . $filename);
    if (!file_exists($path)) return response()->json(['error' => 'File not found: ' . $folder . '/' . $filename], 404);
    return response(file_get_contents($path), 200)
        ->header('Content-Type', mime_content_type($path))
        ->header('Cache-Control', 'public, max-age=31536000');
});

// =============================================================================
// ROUTES DE TEST D'UPLOAD (sans auth)
// =============================================================================
Route::post('/test-upload', function () {
    if (request()->hasFile('test_file')) {
        $file = request()->file('test_file');
        if ($file->isValid()) {
            $filename = 'test_' . time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('ambassadors', $filename, 'public');
            return response()->json(['success' => true, 'filename' => $filename, 'path' => $path]);
        }
        return response()->json(['success' => false, 'message' => 'Fichier invalide']);
    }
    return response()->json(['success' => false, 'message' => 'Aucun fichier fourni']);
});

Route::post('/test-ambassador-upload', function () {
    if (request()->hasFile('avatar')) {
        $file = request()->file('avatar');
        if ($file->isValid()) {
            $filename = 'ambassador_' . time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('ambassadors', $filename, 'public');
            return response()->json([
                'success' => true,
                'filename' => $filename,
                'path' => $path,
                'url' => url('/api/storage/ambassadors/' . $filename),
                'file_info' => [
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType()
                ]
            ]);
        }
        return response()->json(['success' => false, 'message' => 'Fichier invalide']);
    }
    return response()->json(['success' => false, 'message' => 'Aucun fichier avatar fourni']);
});

// =============================================================================
// AUTH PUBLIQUE
// =============================================================================
Route::post('/register', [RegisterController::class, 'register']);
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login');
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

// =============================================================================
// ROUTES PUBLIQUES (aucune authentification requise)
// =============================================================================

// Agents de recharge
Route::get('/recharge-agents', [RechargeAgentController::class, 'index']);
Route::get('/agent-crypto/agents', [\App\Http\Controllers\API\AgentCryptoController::class, 'publicAgents']);

// Fédérations
Route::get('/federations', [FederationController::class, 'index']);
Route::get('/federations/{id}', [FederationController::class, 'show']);
Route::get('/federations/{id}/tournaments', [FederationController::class, 'tournaments']);

// Championnats
Route::prefix('championships')->group(function () {
    Route::get('/', [ChampionshipController::class, 'index']);
    Route::get('/upcoming-matches', [ChampionshipController::class, 'upcomingMatches']);
    Route::get('/{id}', [ChampionshipController::class, 'show']);
    Route::get('/{id}/standings', [ChampionshipController::class, 'standings']);
});

// Méthodes de paiement
Route::get('/payment-methods', function (Request $request) {
    if (!\Illuminate\Support\Facades\Schema::hasTable('payment_methods')) {
        \Illuminate\Support\Facades\Schema::create('payment_methods', function ($table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('type', ['deposit', 'withdrawal']);
            $table->string('method_key');
            $table->boolean('is_active')->default(true);
            $table->decimal('min_amount', 10, 2)->nullable();
            $table->decimal('max_amount', 10, 2)->nullable();
            $table->decimal('fee_percentage', 5, 2)->nullable();
            $table->decimal('fee_fixed', 10, 2)->nullable();
            $table->string('crypto_address')->nullable();
            $table->string('crypto_network')->nullable();
            $table->string('mobile_money_provider')->nullable();
            $table->string('bank_name')->nullable();
            $table->timestamps();
        });

        $methods = [
            ['USDT (TRC20)', 'Dépôt via USDT sur le réseau TRON', 'deposit', 'crypto', 1, 5, 10000, 0, null, 'TSf7x19gfn72Jk4Ah4RWVYuGxvYt5HMWqc', 'TRON (TRC20)', null, null],
            ['USDT (ERC20)', 'Dépôt via USDT sur le réseau Ethereum', 'deposit', 'crypto', 1, 5, 10000, 0, null, '0xAbc123Def456Ghi789Jkl0Mno1Pqr2Stu3Vwx4Yz5', 'Ethereum (ERC20)', null, null],
            ['Bitcoin (BTC)', 'Dépôt via Bitcoin', 'deposit', 'crypto', 1, 20, 20000, 1, null, 'bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh', 'Bitcoin', null, null],
            ['Ethereum (ETH)', 'Dépôt via Ethereum', 'deposit', 'crypto', 1, 10, 15000, 0.5, null, '0x742d35Cc6634C0532925a3b844Bc454e4438f444', 'Ethereum', null, null],
            ['Cash via Agent', 'Dépôt en espèces via un agent de recharge', 'deposit', 'cash', 1, 1, 5000, null, 0.5, null, null, null, null],
            ['USDT (TRC20)', 'Retrait via USDT sur le réseau TRON', 'withdrawal', 'crypto', 1, 10, 50000, 0.5, 1, null, 'TRON (TRC20)', null, null],
            ['USDT (ERC20)', 'Retrait via USDT sur le réseau Ethereum', 'withdrawal', 'crypto', 1, 10, 50000, 0.8, 2, null, 'Ethereum (ERC20)', null, null],
            ['Bitcoin (BTC)', 'Retrait via Bitcoin', 'withdrawal', 'crypto', 1, 50, 100000, 1.5, 5, null, 'Bitcoin', null, null],
            ['Ethereum (ETH)', 'Retrait via Ethereum', 'withdrawal', 'crypto', 1, 30, 80000, 1.2, 3, null, 'Ethereum', null, null],
            ['Virement Bancaire', 'Retrait par virement bancaire', 'withdrawal', 'bank_transfer', 1, 20, 25000, null, 2, null, null, null, 'Any Bank'],
            ['MTN Mobile Money', 'Retrait via MTN Mobile Money', 'withdrawal', 'mobile_money', 1, 5, 1000, 1, null, null, null, 'MTN', null],
            ['Orange Money', 'Retrait via Orange Money', 'withdrawal', 'mobile_money', 1, 5, 1000, 1, null, null, null, 'Orange', null],
            ['Moov Money', 'Retrait via Moov Money', 'withdrawal', 'mobile_money', 1, 5, 1000, 1, null, null, null, 'Moov', null],
        ];

        foreach ($methods as $method) {
            list($name, $desc, $type, $key, $active, $min, $max, $feePct, $feeFixed, $cryptoAddr, $cryptoNet, $mobileProvider, $bankName) = $method;
            \Illuminate\Support\Facades\DB::table('payment_methods')->insert([
                'name' => $name,
                'description' => $desc,
                'type' => $type,
                'method_key' => $key,
                'is_active' => $active,
                'min_amount' => $min,
                'max_amount' => $max,
                'fee_percentage' => $feePct,
                'fee_fixed' => $feeFixed,
                'crypto_address' => $cryptoAddr,
                'crypto_network' => $cryptoNet,
                'mobile_money_provider' => $mobileProvider,
                'bank_name' => $bankName,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    $query = \App\Models\PaymentMethod::where('is_active', true);
    if ($request->has('type')) $query->where('type', $request->type);
    if ($request->has('method_key')) $query->where('method_key', $request->method_key);

    return response()->json([
        'success' => true,
        'data' => $query->orderBy('type')->orderBy('name')->get()
    ]);
});

// EbetStars & Ambassadeurs
Route::get('/ebetstars', [EbetStarController::class, 'index']);
Route::get('/ambassadors', [AmbassadorController::class, 'index']);
Route::get('/ambassadors/{id}', [AmbassadorController::class, 'show']);

// Top players
Route::get('/top-players', [TopPlayersController::class, 'index']);
Route::get('/top-players/{id}', [TopPlayersController::class, 'show']);

// Partenaires
Route::get('/partners', [PartnerController::class, 'index']);
Route::get('/partners/{id}', [PartnerController::class, 'show']);

// Partenaires
Route::get('/streams', [StreamController::class, 'index']);
Route::get('/streams/{id}', [StreamController::class, 'show']);
Route::get('/streams/{id}/chat', [StreamController::class, 'getChatMessages']);
Route::get('/streams/{id}/donations', [DonationController::class, 'index']);
Route::get('/streams/{id}/predictions', [StreamPredictionController::class, 'streamStats']);
Route::get('/sponsored-matches', [SponsoredMatchController::class, 'index']);
Route::get('/sponsored-matches/{id}', [SponsoredMatchController::class, 'show']);

// Challenges (lecture publique)
Route::get('/challenges/live/list', [ChallengeController::class, 'liveChallenges']);
Route::get('/challenges/{id}/live', [ChallengeController::class, 'getLiveStream']);
Route::get('/challenges/{id}/bets', [ChallengeController::class, 'getChallengeBets']);

// Événements (lecture publique)
Route::get('/events', [EventController::class, 'index']);
Route::get('/events/{id}', [EventController::class, 'show']);
Route::post('/events/{id}/register', [EventController::class, 'register']); // inscription sans compte

// Certification & agent requests (soumission publique sans compte)
Route::post('/certification-requests', [CertificationRequestController::class, 'store']);
Route::post('/agent-requests', [AgentRequestController::class, 'store']);

// Clans (lecture publique)
Route::get('/clans', [ClanController::class, 'index']);
Route::get('/clans/{id}', [ClanController::class, 'show']);

// EBETSTREAM ARENA
Route::prefix('arena')->group(function () {
    Route::get('/stats', [\App\Http\Controllers\API\ArenaController::class, 'stats']);
    Route::get('/matches', [\App\Http\Controllers\API\ArenaController::class, 'matches']);
    Route::get('/matches/{id}', [\App\Http\Controllers\API\ArenaController::class, 'show']);
    Route::get('/leaderboard', [\App\Http\Controllers\API\ArenaController::class, 'leaderboard']);
});

// Ballon d'Or (lecture publique)
Route::get('/ballon-dor/current-season', [BallonDorController::class, 'getCurrentSeason']);
Route::get('/ballon-dor/seasons', [BallonDorController::class, 'getSeasons']);
Route::get('/ballon-dor/nominations', [BallonDorController::class, 'getNominations']);
Route::get('/ballon-dor/nominations/{seasonId}', [BallonDorController::class, 'getNominations']);
Route::get('/ballon-dor/results', [BallonDorController::class, 'getResults']);

// Team Marketplace (lecture publique)
Route::get('/team-marketplace', [TeamMarketplaceController::class, 'index']);
Route::get('/team-marketplace/{id}', [TeamMarketplaceController::class, 'show']);

// Tournois (lecture publique)
Route::get('/tournaments/{id}/teams', [TournamentController::class, 'getTeams']);

// =============================================================================
// ROUTES PROTÉGÉES (auth:sanctum requis)
// =============================================================================
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {

    // --- Auth ---
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Recherche d'utilisateurs (pour les défis)
    Route::get('/users/search', function (Request $request) {
        $query = $request->get('q', '');
        if (strlen($query) < 2) {
            return response()->json(['success' => true, 'data' => []]);
        }
        $users = \App\Models\User::where('username', 'like', '%' . $query . '%')
            ->where('id', '!=', $request->user()->id)
            ->select('id', 'username', 'email')
            ->limit(10)
            ->get();
        return response()->json(['success' => true, 'data' => $users]);
    });

    // --- Portefeuille ---
    Route::get('/wallet', function (Request $request) {
        $user = $request->user();
        $wallet = \App\Models\Wallet::firstOrCreate(
            ['user_id' => $user->id],
            ['balance' => 0, 'locked_balance' => 0, 'currency' => 'EBT']
        );
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $wallet->id,
                'user_id' => $wallet->user_id,
                'balance' => $wallet->balance,
                'locked_balance' => $wallet->locked_balance,
                'available_balance' => $wallet->balance - $wallet->locked_balance,
                'currency' => $wallet->currency,
            ]
        ]);
    });

    // --- Bonus ---
    Route::get('/bonuses', function (Request $request) {
        $user = $request->user();

        $bonusTransactions = \App\Models\Transaction::where('user_id', $user->id)
            ->where('type', 'deposit')
            ->whereIn('provider', ['welcome_bonus', 'first_deposit_bonus', 'referral_bonus'])
            ->whereIn('status', ['locked', 'confirmed'])
            ->orderBy('created_at', 'desc')
            ->get();

        $bonuses = [];

        foreach ($bonusTransactions as $transaction) {
            $bonusType = $transaction->provider;
            $bonusAmount = (float) $transaction->amount;
            $createdAt = $transaction->created_at;
            $withdrawalConditions = [];
            $canWithdraw = false;
            $withdrawalMessage = '';

            if (in_array($bonusType, ['welcome_bonus', 'referral_bonus'])) {
                $daysSinceCreation = now()->diffInDays($createdAt);
                $betCount = \App\Models\Bet::where('user_id', $user->id)->where('status', 'completed')->count();
                $withdrawalConditions = [
                    'type' => $bonusType,
                    'conditions' => [
                        'option_1' => [
                            'description' => 'Wait 30 days after registration',
                            'completed' => $daysSinceCreation >= 30,
                            'progress' => min(100, ($daysSinceCreation / 30) * 100),
                            'remaining' => round(max(0, 30 - $daysSinceCreation), 1) . ' days'
                        ],
                        'option_2' => [
                            'description' => 'Place 10 validated bets',
                            'completed' => $betCount >= 10,
                            'progress' => min(100, ($betCount / 10) * 100),
                            'remaining' => max(0, 10 - $betCount) . ' bets'
                        ]
                    ]
                ];
                $canWithdraw = $daysSinceCreation >= 30 || $betCount >= 10;
                $withdrawalMessage = $canWithdraw ? 'Withdrawal available' :
                    'Conditions not met: ' . ($daysSinceCreation < 30 ? 'Wait ' . round(30 - $daysSinceCreation, 1) . ' days' : '') .
                    ($betCount < 10 ? ($daysSinceCreation < 30 ? ' OR place ' : 'Place ') . (10 - $betCount) . ' bets' : '');
            } elseif ($bonusType === 'first_deposit_bonus') {
                $daysSinceCreation = now()->diffInDays($createdAt);
                $depositCount = \App\Models\Deposit::where('user_id', $user->id)->where('status', 'approved')->count();
                $betCount = \App\Models\Bet::where('user_id', $user->id)->where('status', 'completed')->count();
                $hasValidDeposit = $depositCount >= 1;
                $withdrawalConditions = [
                    'type' => 'first_deposit_bonus',
                    'conditions' => [
                        'option_1' => [
                            'description' => 'Complete 1 validated deposit AND wait 30 days',
                            'completed' => $hasValidDeposit && $daysSinceCreation >= 30,
                            'progress' => min(100, (($hasValidDeposit ? 50 : 0) + (min($daysSinceCreation, 30) / 30 * 50))),
                            'deposit_completed' => $hasValidDeposit,
                            'days_completed' => $daysSinceCreation >= 30,
                            'remaining' => (!$hasValidDeposit ? '1 validated deposit' : '') . ($daysSinceCreation < 30 ? (!$hasValidDeposit ? ' + ' : '') . round(max(0, 30 - $daysSinceCreation), 1) . ' days' : '')
                        ],
                        'option_2' => [
                            'description' => 'Place 20 validated bets',
                            'completed' => $betCount >= 20,
                            'progress' => min(100, ($betCount / 20) * 100),
                            'remaining' => max(0, 20 - $betCount) . ' bets'
                        ]
                    ]
                ];
                $canWithdraw = ($hasValidDeposit && $daysSinceCreation >= 30) || $betCount >= 20;
                $withdrawalMessage = $canWithdraw ? 'Withdrawal available' : 'Conditions not met';
            }

            $isLocked = $transaction->status === 'locked';
            $bonuses[] = [
                'id' => $transaction->id,
                'type' => $bonusType,
                'type_label' => $bonusType === 'welcome_bonus' ? 'Registration Bonus' : ($bonusType === 'first_deposit_bonus' ? 'First Deposit Bonus' : 'Referral Bonus'),
                'amount' => $bonusAmount,
                'status' => $transaction->status,
                'is_locked' => $isLocked,
                'created_at' => $createdAt->toISOString(),
                'withdrawal_conditions' => $withdrawalConditions,
                'can_withdraw' => $canWithdraw && $isLocked,
                'withdrawal_message' => $isLocked ? $withdrawalMessage : 'Already withdrawn',
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $bonuses,
            'total_bonuses' => count($bonuses),
            'total_amount' => array_sum(array_column($bonuses, 'amount')),
        ]);
    });

    // --- Dépôts ---
    Route::prefix('deposits')->group(function () {
        Route::post('/', [DepositController::class, 'store']);
        Route::get('/', [DepositController::class, 'index']);
        Route::get('/{id}', [DepositController::class, 'show']);
    });

    // --- Retraits ---
    Route::prefix('withdrawals')->group(function () {
        Route::post('/', [WithdrawalController::class, 'store']);
        Route::get('/', [WithdrawalController::class, 'index']);
        Route::get('/{id}', [WithdrawalController::class, 'show']);
    });

    // --- Challenges (actions authentifiées uniquement) ---
    Route::prefix('challenges')->group(function () {
        Route::get('/', [ChallengeController::class, 'index']);
        Route::post('/', [ChallengeController::class, 'store']);
        Route::get('/{id}', [ChallengeController::class, 'show']);
        Route::post('/{id}/accept', [ChallengeController::class, 'accept']);
        Route::post('/{id}/cancel', [ChallengeController::class, 'cancel']);
        Route::post('/{id}/scores', [ChallengeController::class, 'submitScores']);
        Route::get('/{id}/messages', [ChallengeController::class, 'getMessages']);
        Route::post('/{id}/messages', [ChallengeController::class, 'sendMessage']);
        Route::delete('/{id}/messages/{messageId}', [ChallengeController::class, 'deleteMessage']);
        Route::post('/{id}/request-stop', [ChallengeController::class, 'requestStop']);
        Route::get('/{id}/stop-request', [ChallengeController::class, 'getStopRequest']);
        Route::delete('/{id}/stop-request', [ChallengeController::class, 'cancelStopRequest']);
        Route::post('/{id}/screen-recording/start', [ChallengeController::class, 'startScreenRecording']);
        Route::post('/{id}/screen-recording/stop', [ChallengeController::class, 'stopScreenRecording']);
        Route::post('/{id}/screen-recording/pause', [ChallengeController::class, 'pauseScreenRecording']);
        Route::post('/{id}/screen-recording/resume', [ChallengeController::class, 'resumeScreenRecording']);
        Route::post('/{id}/viewer-count', [ChallengeController::class, 'updateViewerCount']);
        Route::post('/{id}/bet', [ChallengeController::class, 'betOnChallenge']);
        // Clan challenges
        Route::post('/clans', [ChallengeController::class, 'storeClanChallenge']);
        Route::post('/clans/{id}/accept', [ChallengeController::class, 'acceptClanChallenge']);
    });

    // --- Certification requests (lecture authentifiée) ---
    Route::prefix('certification-requests')->group(function () {
        Route::get('/', [CertificationRequestController::class, 'index']);
        Route::get('/{id}', [CertificationRequestController::class, 'show']);
    });

    // --- Clans (actions authentifiées) ---
    Route::prefix('clans')->group(function () {
        Route::post('/', [ClanController::class, 'store']);
        Route::post('/{id}/join', [ClanController::class, 'join']);
        Route::post('/{id}/leave', [ClanController::class, 'leave']);
        Route::post('/{id}/apply-leadership', [ClanController::class, 'applyForLeadership']);
        Route::post('/{id}/candidates/{candidateId}/vote', [ClanController::class, 'vote']);
        Route::get('/{id}/messages', [ClanController::class, 'getMessages']);
        Route::post('/{id}/messages', [ClanController::class, 'sendMessage']);
    });

    // --- Streams (actions authentifiées uniquement) ---
    Route::prefix('streams')->group(function () {
        Route::post('/', [StreamController::class, 'store']);
        Route::put('/{id}', [StreamController::class, 'update']);
        Route::post('/{id}/start', [StreamController::class, 'start']);
        Route::post('/{id}/stop', [StreamController::class, 'stop']);
        Route::post('/{id}/chat', [StreamController::class, 'sendChatMessage']);
        Route::delete('/{id}/chat/{messageId}', [StreamController::class, 'deleteChatMessage']);
        Route::post('/{id}/follow', [StreamController::class, 'toggleFollow']);
        Route::post('/{id}/viewers', [StreamController::class, 'updateViewers']);
        // ── Monetisation Sources A & B ──
        Route::post('/{id}/donate', [DonationController::class, 'donate']);
        Route::post('/{id}/predict', [StreamPredictionController::class, 'predict']);
    });
    Route::get('/stream-key', [StreamController::class, 'getStreamKey']);

    // ── Source A : Donations earnings ──
    Route::get('/my-donations', [DonationController::class, 'myEarnings']);

    // ── Source C : Sponsored matches ──
    Route::prefix('sponsored-matches')->group(function () {
        Route::get('/',           [SponsoredMatchController::class, 'index']);
        Route::post('/',          [SponsoredMatchController::class, 'store']);
        Route::get('/{id}',       [SponsoredMatchController::class, 'show']);
    });

    // --- Profil ---
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show']);
        Route::post('/', [ProfileController::class, 'update']);
        Route::put('/', [ProfileController::class, 'update']);
        Route::get('/qr-code', [ProfileController::class, 'getQRCode']);
    });

    // --- Certification ---
    Route::prefix('certification')->group(function () {
        Route::get('/eligibility', [CertificationController::class, 'checkEligibility']);
        Route::get('/status', [CertificationController::class, 'getStatus']);
        Route::post('/request', [CertificationController::class, 'submitRequest']);
    });

    // --- Tournois (actions authentifiées) ---
    Route::prefix('tournaments')->group(function () {
        Route::post('/{id}/register-team', [TournamentController::class, 'registerTeam']);
        Route::get('/{id}/my-teams', [TournamentController::class, 'getMyTeams']);
    });

    // --- Fédérations (actions authentifiées) ---
    Route::prefix('federations')->group(function () {
        Route::get('/my-federation', [FederationController::class, 'myFederation']);
        Route::post('/', [FederationController::class, 'store']);
        Route::put('/{id}', [FederationController::class, 'update']);
    });

    // --- Ballon d'Or (actions authentifiées) ---
    Route::prefix('ballon-dor')->group(function () {
        Route::get('/my-votes', [BallonDorController::class, 'getUserVotes']);
        Route::get('/my-votes/{seasonId}', [BallonDorController::class, 'getUserVotes']);
        Route::get('/can-vote/{category}', [BallonDorController::class, 'canVote']);
        Route::post('/vote', [BallonDorController::class, 'vote']);
    });

    // --- Championnats (actions authentifiées) ---
    Route::prefix('championships')->group(function () {
        Route::post('/{id}/register', [ChampionshipController::class, 'register']);
    });

    // --- Team Marketplace (actions authentifiées) ---
    Route::prefix('team-marketplace')->group(function () {
        Route::get('/my-teams', [TeamMarketplaceController::class, 'myTeams']);
        Route::get('/my-listings', [TeamMarketplaceController::class, 'myListings']);
        Route::post('/', [TeamMarketplaceController::class, 'store']);
        Route::post('/{id}/cancel', [TeamMarketplaceController::class, 'cancel']);
        Route::post('/{id}/buy', [TeamMarketplaceController::class, 'buy']);
        Route::post('/{id}/loan', [TeamMarketplaceController::class, 'loan']);
    });

    // --- Agent requests (lecture authentifiée) ---
    Route::prefix('agent-requests')->group(function () {
        Route::get('/', [AgentRequestController::class, 'index']);
        Route::get('/{id}', [AgentRequestController::class, 'show']);
    });

    // --- Codes de retrait (utilisateur authentifié) ---
    Route::prefix('withdrawal-codes')->group(function () {
        Route::get('/', [WithdrawalCodeController::class, 'index']);
        Route::post('/', [WithdrawalCodeController::class, 'store']);
        Route::get('/agents/active', [WithdrawalCodeController::class, 'getActiveAgents']); // AVANT /{code}
        Route::get('/{code}', [WithdrawalCodeController::class, 'show']);
        Route::post('/{code}/cancel', [WithdrawalCodeController::class, 'cancel']);
    });

    // --- Paris ---
    Route::prefix('bets')->group(function () {
        Route::get('/', [BetController::class, 'index']);
        Route::post('/', [BetController::class, 'store']);
    });

    // EBETSTREAM ARENA (auth)
    Route::prefix('arena')->group(function () {
        Route::get('/profile', [\App\Http\Controllers\API\ArenaController::class, 'profile']);
        Route::post('/profile', [\App\Http\Controllers\API\ArenaController::class, 'saveProfile']);
        Route::post('/quick-match', [\App\Http\Controllers\API\ArenaController::class, 'quickMatch']);
        Route::post('/ranked-match', [\App\Http\Controllers\API\ArenaController::class, 'rankedMatch']);
        Route::post('/tournament-match', [\App\Http\Controllers\API\ArenaController::class, 'createTournamentMatch']);
        Route::post('/matches', [\App\Http\Controllers\API\ArenaController::class, 'createPrivateMatch']);
        Route::post('/matches/{id}/join', [\App\Http\Controllers\API\ArenaController::class, 'joinMatch']);
        Route::post('/matches/{id}/leave', [\App\Http\Controllers\API\ArenaController::class, 'leaveMatch']);
    });

    // Agent Crypto EBETSTREAM
    Route::prefix('agent-crypto')->group(function () {
        Route::get('/dashboard', [\App\Http\Controllers\API\AgentCryptoController::class, 'dashboard']);
        Route::post('/crypto-deposit', [\App\Http\Controllers\API\AgentCryptoController::class, 'requestCryptoDeposit']);
        Route::post('/deposit-to-player', [\App\Http\Controllers\API\AgentCryptoController::class, 'depositToPlayer']);
        Route::post('/withdrawals/{code}/complete', [\App\Http\Controllers\API\AgentCryptoController::class, 'completeWithdrawal']);
        Route::get('/transfers', [\App\Http\Controllers\API\AgentCryptoController::class, 'transfers']);
        Route::post('/rate', [\App\Http\Controllers\API\AgentCryptoController::class, 'rateAgent']);
    });

    // =========================================================================
    // ROUTES ADMIN
    // =========================================================================

    // Admin - Ambassadeurs
    Route::prefix('admin/ambassadors')->group(function () {
        Route::post('/', [AmbassadorController::class, 'store']);
        Route::put('/{id}', [AmbassadorController::class, 'update']);
        Route::delete('/{id}', [AmbassadorController::class, 'destroy']);
    });

    // Admin - Partenaires
    Route::prefix('admin/partners')->group(function () {
        Route::post('/', [PartnerController::class, 'store']);
        Route::put('/{id}', [PartnerController::class, 'update']);
        Route::delete('/{id}', [PartnerController::class, 'destroy']);
    });

    // Admin - Événements
    Route::prefix('admin/events')->group(function () {
        Route::get('/', [EventController::class, 'index']);
        Route::post('/', [EventController::class, 'store']);
        Route::put('/{id}', [EventController::class, 'update']);
        Route::delete('/{id}', [EventController::class, 'destroy']);
        Route::get('/{id}/registrations', [EventController::class, 'getRegistrations']);
    });

    // Admin - Streams
    Route::prefix('admin/streams')->group(function () {
        Route::get('/', [StreamController::class, 'adminIndex']);
        Route::post('/force-stop-all', [StreamController::class, 'forceStopAll']);
        Route::get('/{id}', [StreamController::class, 'adminShow']);
        Route::put('/{id}', [StreamController::class, 'adminUpdate']);
        Route::delete('/{id}', [StreamController::class, 'adminDestroy']);
        Route::post('/{id}/force-stop', [StreamController::class, 'forceStop']);
        Route::get('/{id}/sessions', [StreamController::class, 'getSessions']);
        Route::get('/{id}/chat', [StreamController::class, 'getChatMessages']);
        Route::delete('/{id}/chat/{messageId}', [StreamController::class, 'adminDeleteChatMessage']);
    });

    // Admin - Clans
    Route::prefix('admin/clans')->group(function () {
        Route::get('/', [ClanController::class, 'adminIndex']);
        Route::get('/{id}', [ClanController::class, 'adminShow']);
        Route::put('/{id}', [ClanController::class, 'adminUpdate']);
        Route::delete('/{id}', [ClanController::class, 'adminDestroy']);
        Route::delete('/{id}/members/{userId}', [ClanController::class, 'adminRemoveMember']);
        Route::post('/{id}/approve-leader', [ClanController::class, 'approveLeader']);
    });

    // Admin - Général (stats, users, dépôts, retraits, certifications)
    Route::prefix('admin')->group(function () {
        Route::get('/stats', [AdminController::class, 'stats']);
        Route::get('/users', [AdminController::class, 'users']);
        Route::post('/users', [AdminController::class, 'createUser']);
        Route::put('/users/{id}', [AdminController::class, 'updateUser']);
        Route::delete('/users/{id}', [AdminController::class, 'deleteUser']);
        Route::post('/users/{id}/balance', [AdminController::class, 'updateUserBalance']);
        Route::get('/deposits', [AdminController::class, 'deposits']);
        Route::post('/deposits/{id}/approve', [AdminController::class, 'approveDeposit']);
        Route::post('/deposits/{id}/reject', [AdminController::class, 'rejectDeposit']);
        Route::get('/withdrawals', [AdminController::class, 'withdrawals']);
        Route::post('/withdrawals/{id}/approve', [AdminController::class, 'approveWithdrawal']);
        Route::post('/withdrawals/{id}/reject', [AdminController::class, 'rejectWithdrawal']);
        Route::get('/certifications', [AdminController::class, 'certifications']);
        Route::get('/certifications/{id}', [AdminController::class, 'getCertification']);
        Route::post('/certifications/{id}/approve', [AdminController::class, 'approveCertification']);
        Route::post('/certifications/{id}/reject', [AdminController::class, 'rejectCertification']);
    });

    // Admin - Méthodes de paiement
    Route::prefix('admin/payment-methods')->group(function () {
        Route::get('/', [AdminPaymentMethodController::class, 'index']);
        Route::get('/{id}', [AdminPaymentMethodController::class, 'show']);
        Route::post('/', [AdminPaymentMethodController::class, 'store']);
        Route::put('/{id}', [AdminPaymentMethodController::class, 'update']);
        Route::delete('/{id}', [AdminPaymentMethodController::class, 'destroy']);
    });

    // Admin - Codes promo
    Route::prefix('admin/promo-codes')->group(function () {
        Route::get('/', [AdminPromoCodeController::class, 'index']);
        Route::get('/{id}', [AdminPromoCodeController::class, 'show']);
        Route::post('/', [AdminPromoCodeController::class, 'store']);
        Route::put('/{id}', [AdminPromoCodeController::class, 'update']);
        Route::delete('/{id}', [AdminPromoCodeController::class, 'destroy']);
    });

    // Admin - Monetization program
    Route::prefix('admin/monetization')->group(function () {
        Route::get('/', [AdminMonetizationController::class, 'index']);
        Route::put('/settings/{settingKey}', [AdminMonetizationController::class, 'updateSetting']);

        Route::post('/streamer-tiers', [AdminMonetizationController::class, 'storeStreamerTier']);
        Route::put('/streamer-tiers/{id}', [AdminMonetizationController::class, 'updateStreamerTier']);
        Route::delete('/streamer-tiers/{id}', [AdminMonetizationController::class, 'destroyStreamerTier']);

        Route::post('/agent-tiers', [AdminMonetizationController::class, 'storeAgentTier']);
        Route::put('/agent-tiers/{id}', [AdminMonetizationController::class, 'updateAgentTier']);
        Route::delete('/agent-tiers/{id}', [AdminMonetizationController::class, 'destroyAgentTier']);

        // Source C : distribution prize pool
        Route::post('/sponsored-matches/{id}/distribute', [SponsoredMatchController::class, 'distribute']);
    });

    // Admin - EBETSTREAM ARENA
    Route::prefix('admin/arena')->group(function () {
        Route::get('/', [\App\Http\Controllers\API\AdminArenaController::class, 'index']);
        Route::get('/{id}', [\App\Http\Controllers\API\AdminArenaController::class, 'show']);
        Route::post('/', [\App\Http\Controllers\API\AdminArenaController::class, 'store']);
        Route::put('/{id}', [\App\Http\Controllers\API\AdminArenaController::class, 'update']);
        Route::post('/{id}/start', [\App\Http\Controllers\API\AdminArenaController::class, 'startLive']);
        Route::post('/{id}/result', [\App\Http\Controllers\API\AdminArenaController::class, 'setResult']);
        Route::post('/{id}/cancel', [\App\Http\Controllers\API\AdminArenaController::class, 'cancel']);
        Route::delete('/{id}', [\App\Http\Controllers\API\AdminArenaController::class, 'destroy']);
    });

    Route::prefix('admin/agent-crypto')->group(function () {
        Route::get('/deposits', [\App\Http\Controllers\API\AgentCryptoController::class, 'adminListDeposits']);
        Route::post('/deposits/{id}/approve', [\App\Http\Controllers\API\AgentCryptoController::class, 'adminApproveDeposit']);
    });

    // Admin - Paris
    Route::prefix('admin/bets')->group(function () {
        Route::get('/', [AdminBetController::class, 'index']);
        Route::get('/{id}', [AdminBetController::class, 'show']);
        Route::put('/{id}/status', [AdminBetController::class, 'updateStatus']);
        Route::delete('/{id}', [AdminBetController::class, 'destroy']);
    });

    // Admin - Challenges
    Route::prefix('admin/challenges')->group(function () {
        Route::get('/', [AdminChallengeController::class, 'index']);
        // Routes spécifiques AVANT les routes avec {id}
        Route::get('/stop-requests', [AdminChallengeController::class, 'getStopRequests']);
        Route::post('/stop-requests/{id}/approve', [AdminChallengeController::class, 'approveStopRequest']);
        Route::post('/stop-requests/{id}/reject', [AdminChallengeController::class, 'rejectStopRequest']);
        Route::get('/{id}', [AdminChallengeController::class, 'show']);
        Route::get('/{id}/messages', [AdminChallengeController::class, 'getMessages']);
        Route::post('/{id}/complete', [AdminChallengeController::class, 'completeChallenge']);
        Route::post('/{id}/cancel', [AdminChallengeController::class, 'cancelChallenge']);
    });

    // Admin - Certification requests
    Route::prefix('admin/certification-requests')->group(function () {
        Route::get('/', [AdminCertificationRequestController::class, 'index']);
        Route::get('/{id}', [AdminCertificationRequestController::class, 'show']);
        Route::post('/{id}/approve', [AdminCertificationRequestController::class, 'approve']);
        Route::post('/{id}/reject', [AdminCertificationRequestController::class, 'reject']);
        Route::put('/{id}/status', [AdminCertificationRequestController::class, 'updateStatus']);
    });

    // Admin - Agent requests
    Route::prefix('admin/agent-requests')->group(function () {
        Route::get('/', [AdminAgentRequestController::class, 'index']);
        Route::get('/{id}', [AdminAgentRequestController::class, 'show']);
        Route::post('/{id}/approve', [AdminAgentRequestController::class, 'approve']);
        Route::post('/{id}/reject', [AdminAgentRequestController::class, 'reject']);
        Route::put('/{id}/status', [AdminAgentRequestController::class, 'updateStatus']);
    });

    // Admin - Fédérations
    Route::prefix('admin/federations')->group(function () {
        Route::get('/', [AdminFederationController::class, 'index']);
        Route::get('/{id}', [AdminFederationController::class, 'show']);
        Route::put('/{id}', [AdminFederationController::class, 'update']);
        Route::post('/{id}/approve', [AdminFederationController::class, 'approve']);
        Route::post('/{id}/reject', [AdminFederationController::class, 'reject']);
        Route::post('/{id}/suspend', [AdminFederationController::class, 'suspend']);
        Route::delete('/{id}', [AdminFederationController::class, 'destroy']);
    });

    // Admin - Ballon d'Or
    Route::prefix('admin/ballon-dor')->group(function () {
        Route::get('/seasons', [AdminBallonDorController::class, 'seasons']);
        Route::post('/seasons', [AdminBallonDorController::class, 'createSeason']);
        Route::put('/seasons/{id}', [AdminBallonDorController::class, 'updateSeason']);
        Route::get('/seasons/{id}/nominations', [AdminBallonDorController::class, 'nominations']);
        Route::post('/nominations', [AdminBallonDorController::class, 'createNomination']);
        Route::put('/nominations/{id}', [AdminBallonDorController::class, 'updateNomination']);
        Route::post('/seasons/{id}/winners', [AdminBallonDorController::class, 'setWinners']);
        Route::get('/seasons/{id}/voting-rules', [AdminBallonDorController::class, 'votingRules']);
        Route::put('/seasons/{id}/voting-rules', [AdminBallonDorController::class, 'updateVotingRules']);
    });

    // Admin - Championnats
    Route::prefix('admin/championships')->group(function () {
        Route::get('/', [AdminChampionshipController::class, 'index']);
        Route::post('/', [AdminChampionshipController::class, 'store']);
        Route::put('/{id}', [AdminChampionshipController::class, 'update']);
        Route::delete('/{id}', [AdminChampionshipController::class, 'destroy']);
        Route::get('/{id}/registrations', [AdminChampionshipController::class, 'registrations']);
        Route::post('/{id}/registrations/{registrationId}/validate', [AdminChampionshipController::class, 'validateRegistration']);
        Route::post('/{id}/generate-matches', [AdminChampionshipController::class, 'generateMatches']);
        Route::get('/{id}/matches', [AdminChampionshipController::class, 'matches']);
        Route::post('/{id}/matches/{matchId}/result', [AdminChampionshipController::class, 'updateMatchResult']);
    });

    // Admin - Agents de recharge
    Route::prefix('admin/recharge-agents')->group(function () {
        Route::get('/', [RechargeAgentController::class, 'adminIndex']);
        Route::get('/{id}', [RechargeAgentController::class, 'adminShow']);
        Route::post('/', [RechargeAgentController::class, 'store']);
        Route::put('/{id}', [RechargeAgentController::class, 'update']);
        Route::post('/{id}/wallet', [RechargeAgentController::class, 'adjustWallet']);
        Route::post('/{id}/suspend', [RechargeAgentController::class, 'suspend']);
        Route::post('/{id}/activate', [RechargeAgentController::class, 'activate']);
        Route::delete('/{id}', [RechargeAgentController::class, 'destroy']);
    });

    // Admin - Codes de retrait
    Route::prefix('admin/withdrawal-codes')->group(function () {
        Route::get('/', [WithdrawalCodeController::class, 'adminIndex']);
        Route::post('/{id}/complete', [WithdrawalCodeController::class, 'adminComplete']);
        Route::post('/{id}/cancel', [WithdrawalCodeController::class, 'adminCancel']);
    });
});
