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
use App\Http\Controllers\API\GameCategoryController;
use App\Http\Controllers\API\GameController;
use App\Http\Controllers\API\GameMatchController;
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
use App\Http\Controllers\API\TeamMarketplaceController;
use App\Http\Controllers\API\TournamentController;
use App\Http\Controllers\API\ChampionshipController;
use App\Http\Controllers\API\AdminChampionshipController;
use App\Http\Controllers\DepositController;
use App\Http\Controllers\WithdrawalController;

Route::get('/test', function () {
    return response()->json([
        'success' => true,
        'message' => 'API is working!'
    ]);
});

// Deploy check - vérifier que les routes sont à jour (GET /api/deploy-check)
Route::get('/deploy-check', function () {
    return response()->json([
        'ok' => true,
        'federations_route' => true,
        'api_routes_file' => file_exists(base_path('routes/api.php')) ? 'exists' : 'missing',
    ]);
});

// Public federation routes - MUST be before auth group (no login required)
Route::get('/federations', [FederationController::class, 'index']);
Route::get('/federations/{id}', [FederationController::class, 'show']);
Route::get('/federations/{id}/tournaments', [FederationController::class, 'tournaments']);

// Test upload route
Route::post('/test-upload', function () {
    if (request()->hasFile('test_file')) {
        $file = request()->file('test_file');
        
        if ($file->isValid()) {
            $filename = 'test_' . time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('ambassadors', $filename, 'public');
            
            return response()->json([
                'success' => true,
                'message' => 'Fichier uploadé avec succès',
                'filename' => $filename,
                'path' => $path,
                'url' => url('/api/storage/ambassadors/' . $filename)
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Fichier invalide',
                'error' => $file->getErrorMessage()
            ]);
        }
    } else {
        return response()->json([
            'success' => false,
            'message' => 'Aucun fichier fourni'
        ]);
    }
});

// Test upload ambassadeur (sans auth)
Route::post('/test-ambassador-upload', function () {
    if (request()->hasFile('avatar')) {
        $file = request()->file('avatar');
        
        if ($file->isValid()) {
            $filename = 'ambassador_' . time() . '_' . $file->getClientOriginalName();
            $path = $file->storeAs('ambassadors', $filename, 'public');
            
            return response()->json([
                'success' => true,
                'message' => 'Avatar uploadé avec succès',
                'filename' => $filename,
                'path' => $path,
                'url' => url('/api/storage/ambassadors/' . $filename),
                'file_info' => [
                    'original_name' => $file->getClientOriginalName(),
                    'size' => $file->getSize(),
                    'mime_type' => $file->getMimeType()
                ]
            ]);
        } else {
            return response()->json([
                'success' => false,
                'message' => 'Fichier invalide',
                'error' => $file->getErrorMessage(),
                'file_error' => $file->getError()
            ]);
        }
    } else {
        return response()->json([
            'success' => false,
            'message' => 'Aucun fichier avatar fourni',
            'all_files' => request()->allFiles(),
            'request_data' => request()->all()
        ]);
    }
});

// Public route to serve profile photos
Route::get('/storage/profiles/{filename}', function ($filename) {
    $path = storage_path('app/public/profiles/' . $filename);
    
    if (!file_exists($path)) {
        return response()->json(['error' => 'File not found'], 404);
    }
    
    $file = file_get_contents($path);
    $type = mime_content_type($path);
    
    return response($file, 200)
        ->header('Content-Type', $type)
        ->header('Cache-Control', 'public, max-age=31536000');
});

// Public route to serve ambassador photos
Route::get('/storage/ambassadors/{filename}', function ($filename) {
    $path = storage_path('app/public/ambassadors/' . $filename);
    
    if (!file_exists($path)) {
        return response()->json(['error' => 'File not found'], 404);
    }
    
    $file = file_get_contents($path);
    $type = mime_content_type($path);
    
    return response($file, 200)
        ->header('Content-Type', $type)
        ->header('Cache-Control', 'public, max-age=31536000');
});

// Generic storage route for all files
Route::get('/storage/{folder}/{filename}', function ($folder, $filename) {
    // Security: only allow specific folders
    $allowedFolders = ['ambassadors', 'partners', 'profiles', 'events', 'streams', 'certifications', 'games', 'categories'];
    
    if (!in_array($folder, $allowedFolders)) {
        return response()->json(['error' => 'Access denied'], 403);
    }
    
    $path = storage_path('app/public/' . $folder . '/' . $filename);
    
    if (!file_exists($path)) {
        return response()->json(['error' => 'File not found: ' . $folder . '/' . $filename], 404);
    }
    
    $file = file_get_contents($path);
    $type = mime_content_type($path);
    
    return response($file, 200)
        ->header('Content-Type', $type)
        ->header('Cache-Control', 'public, max-age=31536000');
});

// Games storage route for subdirectories
Route::get('/storage/games/{subfolder}/{filename}', function ($subfolder, $filename) {
    // Security: only allow specific subfolders
    $allowedSubfolders = ['icons', 'images'];
    
    if (!in_array($subfolder, $allowedSubfolders)) {
        return response()->json(['error' => 'Access denied'], 403);
    }
    
    $path = storage_path('app/public/games/' . $subfolder . '/' . $filename);
    
    if (!file_exists($path)) {
        return response()->json(['error' => 'File not found: games/' . $subfolder . '/' . $filename], 404);
    }
    
    $file = file_get_contents($path);
    $type = mime_content_type($path);
    
    return response($file, 200)
        ->header('Content-Type', $type)
        ->header('Cache-Control', 'public, max-age=31536000');
});

// Public route to serve event images
Route::get('/storage/events/{filename}', function ($filename) {
    $path = storage_path('app/public/events/' . $filename);
    
    if (!file_exists($path)) {
        return response()->json(['error' => 'File not found'], 404);
    }
    
    $file = file_get_contents($path);
    $type = mime_content_type($path);
    
    return response($file, 200)
        ->header('Content-Type', $type)
        ->header('Cache-Control', 'public, max-age=31536000');
});

// Public route to serve stream thumbnails
Route::get('/storage/streams/thumbnails/{filename}', function ($filename) {
    $path = storage_path('app/public/streams/thumbnails/' . $filename);
    
    if (!file_exists($path)) {
        return response()->json(['error' => 'File not found'], 404);
    }
    
    $file = file_get_contents($path);
    $type = mime_content_type($path);
    
    return response($file, 200)
        ->header('Content-Type', $type)
        ->header('Cache-Control', 'public, max-age=31536000');
});

// Public route to serve partner photos
Route::get('/storage/partners/{filename}', function ($filename) {
    $path = storage_path('app/public/partners/' . $filename);
    
    if (!file_exists($path)) {
        return response()->json(['error' => 'File not found'], 404);
    }
    
    $file = file_get_contents($path);
    $type = mime_content_type($path);
    
    return response($file, 200)
        ->header('Content-Type', $type)
        ->header('Cache-Control', 'public, max-age=31536000');
});

// Public route to serve certification documents
Route::get('/storage/certifications/{folder}/{filename}', function ($folder, $filename) {
    $path = storage_path('app/public/certifications/' . $folder . '/' . $filename);
    
    if (!file_exists($path)) {
        return response()->json(['error' => 'File not found'], 404);
    }
    
    $file = file_get_contents($path);
    $type = mime_content_type($path);
    
    return response($file, 200)
        ->header('Content-Type', $type)
        ->header('Cache-Control', 'public, max-age=31536000');
});

// Register
Route::post('/register', [RegisterController::class, 'register']);

// Login (rate limited)
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login');

// Password Reset (Public)
Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->middleware('throttle:5,1');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:5,1');

// Public routes
Route::get('/recharge-agents', [RechargeAgentController::class, 'index']);

// Public championship routes
Route::prefix('championships')->group(function () {
    Route::get('/', [ChampionshipController::class, 'index']); // List all active championships
    Route::get('/upcoming-matches', [ChampionshipController::class, 'upcomingMatches']); // Get upcoming matches by division
    Route::get('/{id}', [ChampionshipController::class, 'show']); // Get championship details
    Route::get('/{id}/standings', [ChampionshipController::class, 'standings']); // Get championship standings
});

// Public payment methods routes (for users to see available methods)
Route::get('/payment-methods', function (Request $request) {
    // Vérifier et créer la table si nécessaire
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
        
        // Insérer les méthodes par défaut
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
    
    if ($request->has('type')) {
        $query->where('type', $request->type);
    }
    
    if ($request->has('method_key')) {
        $query->where('method_key', $request->method_key);
    }
    
    $methods = $query->orderBy('type', 'asc')
        ->orderBy('name', 'asc')
        ->get();
    
    return response()->json([
        'success' => true,
        'data' => $methods
    ]);
});

// Public ambassador routes
Route::get('/ebetstars', [EbetStarController::class, 'index']);
Route::get('/ambassadors', [AmbassadorController::class, 'index']);
Route::get('/ambassadors/{id}', [AmbassadorController::class, 'show']);

// Public top players routes
Route::get('/top-players', [TopPlayersController::class, 'index']);
Route::get('/top-players/{id}', [TopPlayersController::class, 'show']);

// Public partner routes
Route::get('/partners', [PartnerController::class, 'index']);
Route::get('/partners/{id}', [PartnerController::class, 'show']);

// Public game routes
Route::get('/game-categories', [GameCategoryController::class, 'index']);
Route::get('/game-categories/{id}', [GameCategoryController::class, 'show']);
Route::get('/games', [GameController::class, 'index']);
Route::get('/games/{id}', [GameController::class, 'show']);

// Public game matches routes
Route::get('/game-matches', [GameMatchController::class, 'index']);
Route::get('/game-matches/{id}', [GameMatchController::class, 'show']);

// Public stream routes (viewing streams)
Route::get('/streams', [StreamController::class, 'index']);
Route::get('/streams/{id}', [StreamController::class, 'show']);
Route::get('/streams/{id}/chat', [StreamController::class, 'getChatMessages']);

// Public challenge live stream routes (so observers can view without logging in)
Route::get('/challenges/live/list', [ChallengeController::class, 'liveChallenges']);
Route::get('/challenges/{id}/live', [ChallengeController::class, 'getLiveStream']);

    // Public event routes
Route::get('/events', [EventController::class, 'index']);       // List events
Route::get('/events/{id}', [EventController::class, 'show']);    // Get event
Route::post('/events/{id}/register', [EventController::class, 'register']); // Register to event (public, no account needed)

// Public certification request submission (no account required)
Route::post('/certification-requests', [CertificationRequestController::class, 'store']);

// Public agent request submission (no account required)
Route::post('/agent-requests', [AgentRequestController::class, 'store']);

// Public clan routes
Route::get('/clans', [ClanController::class, 'index']);        // List clans
Route::get('/clans/{id}', [ClanController::class, 'show']);     // Get clan details

// Public ballon-dor routes (no auth required)
Route::get('/ballon-dor/current-season', [BallonDorController::class, 'getCurrentSeason']);
Route::get('/ballon-dor/seasons', [BallonDorController::class, 'getSeasons']);
Route::get('/ballon-dor/nominations', [BallonDorController::class, 'getNominations']);
Route::get('/ballon-dor/nominations/{seasonId}', [BallonDorController::class, 'getNominations']);
Route::get('/ballon-dor/results', [BallonDorController::class, 'getResults']);

// Public team-marketplace routes (no auth required)
Route::get('/team-marketplace', [TeamMarketplaceController::class, 'index']);
Route::get('/team-marketplace/{id}', [TeamMarketplaceController::class, 'show']);

// Protected routes (user must be logged in)
Route::middleware(['auth:sanctum', 'throttle:60,1'])->group(function () {

    // Logout
    Route::post('/logout', [AuthController::class, 'logout']);

    // Get authenticated user
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    // Search users by username (for challenges)
    Route::get('/users/search', function (Request $request) {
        $query = $request->get('q', '');
        
        if (strlen($query) < 2) {
            return response()->json([
                'success' => true,
                'data' => []
            ]);
        }

        $users = \App\Models\User::where('username', 'like', '%' . $query . '%')
            ->where('id', '!=', $request->user()->id) // Exclude current user
            ->select('id', 'username', 'email')
            ->limit(10)
            ->get();

        return response()->json([
            'success' => true,
            'data' => $users
        ]);
    });

    // Deposit routes
    Route::prefix('deposits')->group(function () {
        Route::post('/', [DepositController::class, 'store']);      // Create deposit
        Route::get('/', [DepositController::class, 'index']);        // List user deposits
        Route::get('/{id}', [DepositController::class, 'show']);     // Get specific deposit
    });

    // Withdrawal routes
    Route::prefix('withdrawals')->group(function () {
        Route::post('/', [WithdrawalController::class, 'store']);      // Create withdrawal
        Route::get('/', [WithdrawalController::class, 'index']);        // List user withdrawals
        Route::get('/{id}', [WithdrawalController::class, 'show']);     // Get specific withdrawal
    });

    // Challenge routes (live/list and {id}/live are PUBLIC - defined above, outside auth group)
    Route::prefix('challenges')->group(function () {
        Route::get('/', [ChallengeController::class, 'index']);              // List challenges
        Route::post('/', [ChallengeController::class, 'store']);            // Create challenge (user)
        Route::get('/{id}', [ChallengeController::class, 'show']);           // Get challenge
        Route::post('/{id}/accept', [ChallengeController::class, 'accept']); // Accept challenge
        Route::post('/{id}/cancel', [ChallengeController::class, 'cancel']); // Cancel challenge
        Route::post('/{id}/scores', [ChallengeController::class, 'submitScores']); // Submit scores
        Route::get('/{id}/messages', [ChallengeController::class, 'getMessages']); // Get challenge messages
        Route::post('/{id}/messages', [ChallengeController::class, 'sendMessage']); // Send message
        Route::delete('/{id}/messages/{messageId}', [ChallengeController::class, 'deleteMessage']); // Delete message
        Route::post('/{id}/request-stop', [ChallengeController::class, 'requestStop']); // Request to stop challenge
        Route::get('/{id}/stop-request', [ChallengeController::class, 'getStopRequest']); // Get stop request status
        Route::delete('/{id}/stop-request', [ChallengeController::class, 'cancelStopRequest']); // Cancel stop request
        Route::post('/{id}/screen-recording/start', [ChallengeController::class, 'startScreenRecording'])->middleware('auth:api'); // Start screen recording and live (creator only)
        Route::post('/{id}/screen-recording/stop', [ChallengeController::class, 'stopScreenRecording'])->middleware('auth:api'); // Stop screen recording and live (creator only)
        Route::post('/{id}/screen-recording/pause', [ChallengeController::class, 'pauseScreenRecording'])->middleware('auth:api'); // Pause live (creator only)
        Route::post('/{id}/screen-recording/resume', [ChallengeController::class, 'resumeScreenRecording'])->middleware('auth:api'); // Resume live (creator only)
        Route::post('/{id}/viewer-count', [ChallengeController::class, 'updateViewerCount']); // Update viewer count (public)
        
        // Clan challenge routes
        Route::post('/clans', [ChallengeController::class, 'storeClanChallenge']); // Create clan challenge
        Route::post('/clans/{id}/accept', [ChallengeController::class, 'acceptClanChallenge']); // Accept clan challenge
        
        // Betting on challenges routes
        Route::post('/{id}/bet', [ChallengeController::class, 'betOnChallenge'])->middleware('auth:api'); // Bet on challenge winner (auth required)
        Route::get('/{id}/bets', [ChallengeController::class, 'getChallengeBets']); // Get bets on challenge (public)
    });

    // Certification requests routes
    Route::prefix('certification-requests')->group(function () {
        Route::get('/', [CertificationRequestController::class, 'index']); // Get user's certification requests (auth required)
        Route::get('/{id}', [CertificationRequestController::class, 'show']); // Get specific certification request (auth required)
    });

    // Clan routes
    Route::prefix('clans')->group(function () {
        Route::post('/', [ClanController::class, 'store']);                           // Create clan
        Route::post('/{id}/join', [ClanController::class, 'join']);                  // Join clan
        Route::post('/{id}/leave', [ClanController::class, 'leave']);                // Leave clan
        Route::post('/{id}/apply-leadership', [ClanController::class, 'applyForLeadership']); // Apply for leadership
        Route::post('/{id}/candidates/{candidateId}/vote', [ClanController::class, 'vote']); // Vote for candidate
        Route::get('/{id}/messages', [ClanController::class, 'getMessages']);        // Get clan messages
        Route::post('/{id}/messages', [ClanController::class, 'sendMessage']);       // Send message
    });

    // Stream routes
    Route::prefix('streams')->group(function () {
        Route::get('/', [StreamController::class, 'index']);                    // List streams
        Route::post('/', [StreamController::class, 'store']);                    // Create stream
        Route::get('/{id}', [StreamController::class, 'show']);                 // Get stream
        Route::put('/{id}', [StreamController::class, 'update']);               // Update stream
        Route::post('/{id}/start', [StreamController::class, 'start']);         // Start stream
        Route::post('/{id}/stop', [StreamController::class, 'stop']);           // Stop stream
        Route::get('/{id}/chat', [StreamController::class, 'getChatMessages']); // Get chat messages
        Route::post('/{id}/chat', [StreamController::class, 'sendChatMessage']); // Send chat message
        Route::delete('/{id}/chat/{messageId}', [StreamController::class, 'deleteChatMessage']); // Delete chat message
        Route::post('/{id}/follow', [StreamController::class, 'toggleFollow']); // Follow/Unfollow
        Route::post('/{id}/viewers', [StreamController::class, 'updateViewers']); // Update viewers
    });
    
    // Stream key route (for streamer)
    Route::get('/stream-key', [StreamController::class, 'getStreamKey']);

    // Profile routes
    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'show']); // Get profile
        Route::post('/', [ProfileController::class, 'update']); // Update profile (with file upload)
        Route::put('/', [ProfileController::class, 'update']); // Update profile
        Route::get('/qr-code', [ProfileController::class, 'getQRCode']); // Get QR code
    });

    // Certification routes
    Route::prefix('certification')->group(function () {
        Route::get('/eligibility', [CertificationController::class, 'checkEligibility']); // Check eligibility
        Route::get('/status', [CertificationController::class, 'getStatus']); // Get certification status
        Route::post('/request', [CertificationController::class, 'submitRequest']); // Submit certification request
    });

    // Tournament routes
    Route::prefix('tournaments')->group(function () {
        Route::post('/{id}/register-team', [TournamentController::class, 'registerTeam'])->middleware('auth:api'); // Register team to tournament (auth required)
        Route::get('/{id}/teams', [TournamentController::class, 'getTeams']); // Get participating teams (public)
        Route::get('/{id}/my-teams', [TournamentController::class, 'getMyTeams'])->middleware('auth:api'); // Get user's teams available for registration (auth required)
    });

    // Federation routes (auth-required only; list/detail/tournaments are public, defined above)
    Route::prefix('federations')->group(function () {
        Route::get('/my-federation', [FederationController::class, 'myFederation']);
        Route::post('/', [FederationController::class, 'store']);
        Route::put('/{id}', [FederationController::class, 'update']);
    });

    // Ballon d'Or authenticated routes (public routes defined above)
    Route::prefix('ballon-dor')->group(function () {
        Route::get('/my-votes', [BallonDorController::class, 'getUserVotes']);
        Route::get('/my-votes/{seasonId}', [BallonDorController::class, 'getUserVotes']);
        Route::get('/can-vote/{category}', [BallonDorController::class, 'canVote']);
        Route::post('/vote', [BallonDorController::class, 'vote']);
    });

    // Authenticated championship routes
    Route::prefix('championships')->group(function () {
        Route::post('/{id}/register', [ChampionshipController::class, 'register']); // Register for championship (auth required)
    });

    // Team Marketplace auth-required routes (public GET / and GET /{id} defined above)
    Route::prefix('team-marketplace')->group(function () {
        Route::get('/my-teams', [TeamMarketplaceController::class, 'myTeams']);
        Route::post('/', [TeamMarketplaceController::class, 'store']);
        Route::post('/{id}/cancel', [TeamMarketplaceController::class, 'cancel']);
        Route::post('/{id}/buy', [TeamMarketplaceController::class, 'buy']);
        Route::post('/{id}/loan', [TeamMarketplaceController::class, 'loan']);
        Route::get('/my-listings', [TeamMarketplaceController::class, 'myListings']);
    });

    // Wallet route
    Route::get('/wallet', function (Request $request) {
        $user = $request->user();
        $wallet = \App\Models\Wallet::where('user_id', $user->id)->first();
        
        // Create wallet if doesn't exist (in EBT - Ebetcoin)
        $wallet = \App\Models\Wallet::firstOrCreate(
            ['user_id' => $user->id],
            [
                'balance' => 0,
                'locked_balance' => 0,
                'currency' => 'EBT',
            ]
        );
        
        // Calculate available balance (total balance - locked balance)
        $availableBalance = $wallet->balance - $wallet->locked_balance;
        
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $wallet->id,
                'user_id' => $wallet->user_id,
                'balance' => $wallet->balance,
                'locked_balance' => $wallet->locked_balance,
                'available_balance' => $availableBalance,
                'currency' => $wallet->currency,
            ]
        ]);
    });

    // Bonuses route - Récupère tous les bonus avec leurs conditions de retrait
    Route::get('/bonuses', function (Request $request) {
        $user = $request->user();
        
        // Récupérer toutes les transactions de type bonus (locked ou confirmed)
        // locked = bonus non retirable, soumis à conditions
        // confirmed = bonus déjà retiré et crédité dans la balance
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
            
            // Définir les conditions de retrait selon le type de bonus
            $withdrawalConditions = [];
            $canWithdraw = false;
            $withdrawalMessage = '';
            
            if ($bonusType === 'welcome_bonus') {
                // Bonus d'inscription : retirable après 30 jours OU après 10 paris validés
                $daysSinceCreation = now()->diffInDays($createdAt);
                $betCount = \App\Models\Bet::where('user_id', $user->id)
                    ->where('status', 'completed')
                    ->count();
                
                $withdrawalConditions = [
                    'type' => 'welcome_bonus',
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
                if (!$canWithdraw) {
                    $withdrawalMessage = 'Conditions not met: ' . ($daysSinceCreation < 30 ? 'Wait ' . round(30 - $daysSinceCreation, 1) . ' days' : '') . 
                                       ($betCount < 10 ? ($daysSinceCreation < 30 ? ' OR place ' . (10 - $betCount) . ' bets' : 'Place ' . (10 - $betCount) . ' bets') : '');
                } else {
                    $withdrawalMessage = 'Withdrawal available';
                }
            } elseif ($bonusType === 'first_deposit_bonus') {
                // Bonus premier dépôt : retirable après 1 dépôt validé + 30 jours OU après 20 paris validés
                $daysSinceCreation = now()->diffInDays($createdAt);
                $depositCount = \App\Models\Deposit::where('user_id', $user->id)
                    ->where('status', 'approved')
                    ->count();
                $betCount = \App\Models\Bet::where('user_id', $user->id)
                    ->where('status', 'completed')
                    ->count();
                
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
                if (!$canWithdraw) {
                    $withdrawalMessage = 'Conditions not met';
                } else {
                    $withdrawalMessage = 'Withdrawal available';
                }
            } elseif ($bonusType === 'referral_bonus') {
                // Bonus de parrainage : mêmes conditions que le bonus d'inscription (30 jours OU 10 paris validés)
                $daysSinceCreation = now()->diffInDays($createdAt);
                $betCount = \App\Models\Bet::where('user_id', $user->id)
                    ->where('status', 'completed')
                    ->count();
                
                $withdrawalConditions = [
                    'type' => 'referral_bonus',
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
                if (!$canWithdraw) {
                    $withdrawalMessage = 'Conditions not met: ' . ($daysSinceCreation < 30 ? 'Wait ' . round(30 - $daysSinceCreation, 1) . ' days' : '') . 
                                       ($betCount < 10 ? ($daysSinceCreation < 30 ? ' OR place ' . (10 - $betCount) . ' bets' : 'Place ' . (10 - $betCount) . ' bets') : '');
                } else {
                    $withdrawalMessage = 'Withdrawal available';
                }
            }
            
            // Ne compter que les bonus avec status 'locked' (non encore retirés)
            // Les bonus avec status 'confirmed' sont déjà retirés et crédités
            $isLocked = $transaction->status === 'locked';
            
            $bonuses[] = [
                'id' => $transaction->id,
                'type' => $bonusType,
                'type_label' => $bonusType === 'welcome_bonus' ? 'Registration Bonus' : ($bonusType === 'first_deposit_bonus' ? 'First Deposit Bonus' : 'Referral Bonus'),
                'amount' => $bonusAmount,
                'status' => $transaction->status, // locked ou confirmed
                'is_locked' => $isLocked, // Bonus non encore retiré
                'created_at' => $createdAt->toISOString(),
                'withdrawal_conditions' => $withdrawalConditions,
                'can_withdraw' => $canWithdraw && $isLocked, // Ne peut retirer que si locked et conditions remplies
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

    // Admin ambassador routes (CRUD)
    Route::prefix('admin/ambassadors')->group(function () {
        Route::post('/', [AmbassadorController::class, 'store']);      // Create ambassador
        Route::put('/{id}', [AmbassadorController::class, 'update']);    // Update ambassador
        Route::delete('/{id}', [AmbassadorController::class, 'destroy']); // Delete ambassador
    });

    // Admin partner routes (CRUD)
    Route::prefix('admin/partners')->group(function () {
        Route::post('/', [PartnerController::class, 'store']);      // Create partner
        Route::put('/{id}', [PartnerController::class, 'update']);    // Update partner
        Route::delete('/{id}', [PartnerController::class, 'destroy']); // Delete partner
    });

    // Admin game category routes (CRUD)
    Route::prefix('admin/game-categories')->group(function () {
        Route::get('/', [GameCategoryController::class, 'adminIndex']);   // List all categories (including inactive)
        Route::post('/', [GameCategoryController::class, 'store']);      // Create category
        Route::put('/{id}', [GameCategoryController::class, 'update']);    // Update category
        Route::delete('/{id}', [GameCategoryController::class, 'destroy']); // Delete category
    });

    // Admin game routes (CRUD)
    Route::prefix('admin/games')->group(function () {
        Route::post('/', [GameController::class, 'store']);      // Create game
        Route::put('/{id}', [GameController::class, 'update']);    // Update game
        Route::delete('/{id}', [GameController::class, 'destroy']); // Delete game
    });

    // Admin game match routes (CRUD)
    Route::prefix('admin/game-matches')->group(function () {
        Route::post('/', [GameMatchController::class, 'store']);      // Create match
        Route::put('/{id}', [GameMatchController::class, 'update']);    // Update match
        Route::delete('/{id}', [GameMatchController::class, 'destroy']); // Delete match
    });

    // User bet routes
    Route::prefix('bets')->group(function () {
        Route::get('/', [BetController::class, 'index']);        // List user bets
        Route::post('/', [BetController::class, 'store']);        // Place bet
    });

    // Admin event routes (CRUD)
    Route::prefix('admin/events')->group(function () {
        Route::get('/', [EventController::class, 'index']);            // List all events (admin)
        Route::post('/', [EventController::class, 'store']);           // Create event
        Route::put('/{id}', [EventController::class, 'update']);        // Update event
        Route::delete('/{id}', [EventController::class, 'destroy']);   // Delete event
        Route::get('/{id}/registrations', [EventController::class, 'getRegistrations']); // Get event registrations
    });

    // Admin stream routes
    Route::prefix('admin/streams')->group(function () {
        Route::get('/', [StreamController::class, 'adminIndex']);       // List all streams (admin)
        Route::get('/{id}', [StreamController::class, 'adminShow']);    // Get stream details (admin)
        Route::put('/{id}', [StreamController::class, 'adminUpdate']);   // Update stream (admin)
        Route::delete('/{id}', [StreamController::class, 'adminDestroy']); // Delete stream (admin)
        Route::post('/{id}/force-stop', [StreamController::class, 'forceStop']); // Force stop stream (admin)
        Route::get('/{id}/sessions', [StreamController::class, 'getSessions']); // Get stream sessions
        Route::get('/{id}/chat', [StreamController::class, 'getChatMessages']); // Get chat messages (admin)
        Route::delete('/{id}/chat/{messageId}', [StreamController::class, 'adminDeleteChatMessage']); // Delete chat message (admin)
    });

    // Admin clan routes
    Route::prefix('admin/clans')->group(function () {
        Route::get('/', [ClanController::class, 'adminIndex']);              // List all clans (admin)
        Route::get('/{id}', [ClanController::class, 'adminShow']);             // Get clan details (admin)
        Route::put('/{id}', [ClanController::class, 'adminUpdate']);          // Update clan (admin)
        Route::delete('/{id}', [ClanController::class, 'adminDestroy']);       // Delete clan (admin)
        Route::delete('/{id}/members/{userId}', [ClanController::class, 'adminRemoveMember']); // Remove member (admin)
        Route::post('/{id}/approve-leader', [ClanController::class, 'approveLeader']); // Approve new leader
    });

    // Admin general routes
    Route::prefix('admin')->group(function () {
        Route::get('/stats', [AdminController::class, 'stats']);                    // Get statistics
        Route::get('/users', [AdminController::class, 'users']);                    // List users
        Route::post('/users', [AdminController::class, 'createUser']);              // Create user
        Route::put('/users/{id}', [AdminController::class, 'updateUser']);          // Update user
        Route::delete('/users/{id}', [AdminController::class, 'deleteUser']);      // Delete user
        Route::post('/users/{id}/balance', [AdminController::class, 'updateUserBalance']); // Update user balance
        Route::get('/deposits', [AdminController::class, 'deposits']);              // List deposits
        Route::post('/deposits/{id}/approve', [AdminController::class, 'approveDeposit']);  // Approve deposit
        Route::post('/deposits/{id}/reject', [AdminController::class, 'rejectDeposit']);     // Reject deposit
        Route::get('/withdrawals', [AdminController::class, 'withdrawals']);        // List withdrawals
        Route::post('/withdrawals/{id}/approve', [AdminController::class, 'approveWithdrawal']); // Approve withdrawal
        Route::post('/withdrawals/{id}/reject', [AdminController::class, 'rejectWithdrawal']);    // Reject withdrawal
        Route::get('/certifications', [AdminController::class, 'certifications']);  // List certifications
        Route::get('/certifications/{id}', [AdminController::class, 'getCertification']);  // Get certification details
        Route::post('/certifications/{id}/approve', [AdminController::class, 'approveCertification']);  // Approve certification
        Route::post('/certifications/{id}/reject', [AdminController::class, 'rejectCertification']);     // Reject certification
    });

    // Admin payment methods routes (CRUD)
    Route::prefix('admin/payment-methods')->group(function () {
        Route::get('/', [AdminPaymentMethodController::class, 'index']);        // List payment methods
        Route::get('/{id}', [AdminPaymentMethodController::class, 'show']);       // Get payment method
        Route::post('/', [AdminPaymentMethodController::class, 'store']);        // Create payment method
        Route::put('/{id}', [AdminPaymentMethodController::class, 'update']);    // Update payment method
        Route::delete('/{id}', [AdminPaymentMethodController::class, 'destroy']); // Delete payment method
    });

    // Admin promo codes routes (CRUD)
    Route::prefix('admin/promo-codes')->group(function () {
        Route::get('/', [AdminPromoCodeController::class, 'index']);        // List promo codes
        Route::get('/{id}', [AdminPromoCodeController::class, 'show']);       // Get promo code
        Route::post('/', [AdminPromoCodeController::class, 'store']);        // Create promo code
        Route::put('/{id}', [AdminPromoCodeController::class, 'update']);    // Update promo code
        Route::delete('/{id}', [AdminPromoCodeController::class, 'destroy']); // Delete promo code
    });

    // Admin bets routes
    Route::prefix('admin/bets')->group(function () {
        Route::get('/', [AdminBetController::class, 'index']);              // List all bets
        Route::get('/{id}', [AdminBetController::class, 'show']);            // Get bet
        Route::put('/{id}/status', [AdminBetController::class, 'updateStatus']); // Update bet status
        Route::delete('/{id}', [AdminBetController::class, 'destroy']);     // Delete bet
    });

    // Admin challenge routes
    Route::prefix('admin/challenges')->group(function () {
        Route::get('/', [AdminChallengeController::class, 'index']);           // List all challenges
        // Stop requests routes must be BEFORE routes with {id} parameter
        Route::get('/stop-requests', [AdminChallengeController::class, 'getStopRequests']); // Get pending stop requests
        Route::post('/stop-requests/{id}/approve', [AdminChallengeController::class, 'approveStopRequest']); // Approve stop request
        Route::post('/stop-requests/{id}/reject', [AdminChallengeController::class, 'rejectStopRequest']); // Reject stop request
        // Routes with {id} parameter must be AFTER specific routes
        Route::get('/{id}', [AdminChallengeController::class, 'show']);        // Get specific challenge
        Route::get('/{id}/messages', [AdminChallengeController::class, 'getMessages']); // Get challenge messages (admin)
        Route::post('/{id}/complete', [AdminChallengeController::class, 'completeChallenge']); // Complete challenge manually
        Route::post('/{id}/cancel', [AdminChallengeController::class, 'cancelChallenge']); // Cancel challenge
    });

    // Admin certification requests routes
    Route::prefix('admin/certification-requests')->group(function () {
        Route::get('/', [AdminCertificationRequestController::class, 'index']); // List all certification requests
        Route::get('/{id}', [AdminCertificationRequestController::class, 'show']); // Get specific request
        Route::post('/{id}/approve', [AdminCertificationRequestController::class, 'approve']); // Approve request
        Route::post('/{id}/reject', [AdminCertificationRequestController::class, 'reject']); // Reject request
        Route::put('/{id}/status', [AdminCertificationRequestController::class, 'updateStatus']); // Update status
    });

    // Admin agent requests routes
    Route::prefix('admin/agent-requests')->group(function () {
        Route::get('/', [AdminAgentRequestController::class, 'index']); // List all agent requests
        Route::get('/{id}', [AdminAgentRequestController::class, 'show']); // Get specific request
        Route::post('/{id}/approve', [AdminAgentRequestController::class, 'approve']); // Approve request
        Route::post('/{id}/reject', [AdminAgentRequestController::class, 'reject']); // Reject request
        Route::put('/{id}/status', [AdminAgentRequestController::class, 'updateStatus']); // Update status
    });

    // Admin federation routes
    Route::prefix('admin/federations')->group(function () {
        Route::get('/', [AdminFederationController::class, 'index']); // List all federations
        Route::get('/{id}', [AdminFederationController::class, 'show']); // Get federation
        Route::put('/{id}', [AdminFederationController::class, 'update']); // Update federation
        Route::post('/{id}/approve', [AdminFederationController::class, 'approve']); // Approve federation
        Route::post('/{id}/reject', [AdminFederationController::class, 'reject']); // Reject federation
        Route::post('/{id}/suspend', [AdminFederationController::class, 'suspend']); // Suspend federation
        Route::delete('/{id}', [AdminFederationController::class, 'destroy']); // Delete federation
    });

    // Admin Ballon d'Or routes
    Route::prefix('admin/ballon-dor')->group(function () {
        Route::get('/seasons', [AdminBallonDorController::class, 'seasons']); // List seasons
        Route::post('/seasons', [AdminBallonDorController::class, 'createSeason']); // Create season
        Route::put('/seasons/{id}', [AdminBallonDorController::class, 'updateSeason']); // Update season
        Route::get('/seasons/{id}/nominations', [AdminBallonDorController::class, 'nominations']); // Get nominations
        Route::post('/nominations', [AdminBallonDorController::class, 'createNomination']); // Create nomination
        Route::put('/nominations/{id}', [AdminBallonDorController::class, 'updateNomination']); // Update nomination
        Route::post('/seasons/{id}/winners', [AdminBallonDorController::class, 'setWinners']); // Set winners
        Route::get('/seasons/{id}/voting-rules', [AdminBallonDorController::class, 'votingRules']); // Get voting rules
        Route::put('/seasons/{id}/voting-rules', [AdminBallonDorController::class, 'updateVotingRules']); // Update voting rules
    });

    // Admin championship routes
    Route::prefix('admin/championships')->group(function () {
        Route::get('/', [AdminChampionshipController::class, 'index']); // List all championships
        Route::post('/', [AdminChampionshipController::class, 'store']); // Create championship
        Route::put('/{id}', [AdminChampionshipController::class, 'update']); // Update championship
        Route::delete('/{id}', [AdminChampionshipController::class, 'destroy']); // Delete championship
        Route::get('/{id}/registrations', [AdminChampionshipController::class, 'registrations']); // Get registrations
        Route::post('/{id}/registrations/{registrationId}/validate', [AdminChampionshipController::class, 'validateRegistration']); // Validate/reject registration
        Route::post('/{id}/generate-matches', [AdminChampionshipController::class, 'generateMatches']); // Generate matches
        Route::get('/{id}/matches', [AdminChampionshipController::class, 'matches']); // Get matches
        Route::post('/{id}/matches/{matchId}/result', [AdminChampionshipController::class, 'updateMatchResult']); // Update match result
    });

    // Admin federation routes
    Route::prefix('admin/federations')->group(function () {
        Route::get('/', [AdminFederationController::class, 'index']); // List all federations
        Route::get('/{id}', [AdminFederationController::class, 'show']); // Get federation
        Route::put('/{id}', [AdminFederationController::class, 'update']); // Update federation
        Route::post('/{id}/approve', [AdminFederationController::class, 'approve']); // Approve federation
        Route::post('/{id}/reject', [AdminFederationController::class, 'reject']); // Reject federation
        Route::post('/{id}/suspend', [AdminFederationController::class, 'suspend']); // Suspend federation
        Route::delete('/{id}', [AdminFederationController::class, 'destroy']); // Delete federation
    });

    // Admin Ballon d'Or routes
    Route::prefix('admin/ballon-dor')->group(function () {
        Route::get('/seasons', [AdminBallonDorController::class, 'seasons']); // List seasons
        Route::post('/seasons', [AdminBallonDorController::class, 'createSeason']); // Create season
        Route::put('/seasons/{id}', [AdminBallonDorController::class, 'updateSeason']); // Update season
        Route::get('/seasons/{id}/nominations', [AdminBallonDorController::class, 'nominations']); // Get nominations
        Route::post('/nominations', [AdminBallonDorController::class, 'createNomination']); // Create nomination
        Route::put('/nominations/{id}', [AdminBallonDorController::class, 'updateNomination']); // Update nomination
        Route::post('/seasons/{id}/winners', [AdminBallonDorController::class, 'setWinners']); // Set winners
        Route::get('/seasons/{id}/voting-rules', [AdminBallonDorController::class, 'votingRules']); // Get voting rules
        Route::put('/seasons/{id}/voting-rules', [AdminBallonDorController::class, 'updateVotingRules']); // Update voting rules
    });

    // Admin recharge agents routes
    Route::prefix('admin/recharge-agents')->group(function () {
        Route::get('/', [RechargeAgentController::class, 'adminIndex']); // List all agents (including inactive)
        Route::post('/', [RechargeAgentController::class, 'store']); // Create new agent
        Route::put('/{id}', [RechargeAgentController::class, 'update']); // Update agent
        Route::delete('/{id}', [RechargeAgentController::class, 'destroy']); // Delete agent
    });

    // User agent requests routes (authenticated users can view their own requests)
    Route::prefix('agent-requests')->group(function () {
        Route::get('/', [AgentRequestController::class, 'index']); // List user's agent requests
        Route::get('/{id}', [AgentRequestController::class, 'show']); // Get specific user's request
    });

    // Withdrawal codes routes (authenticated users)
    Route::prefix('withdrawal-codes')->group(function () {
        Route::get('/', [WithdrawalCodeController::class, 'index']); // List user's withdrawal codes
        Route::post('/', [WithdrawalCodeController::class, 'store']); // Create withdrawal code
        Route::get('/{code}', [WithdrawalCodeController::class, 'show']); // Get withdrawal code details
        Route::post('/{code}/cancel', [WithdrawalCodeController::class, 'cancel']); // Cancel withdrawal code
        Route::get('/agents/active', [WithdrawalCodeController::class, 'getActiveAgents']); // Get active agents for withdrawal
    });
});

// Public route for agents to complete withdrawals
Route::post('/withdrawal-codes/{code}/complete', [WithdrawalCodeController::class, 'complete']);

// Admin withdrawal codes management
Route::middleware(['auth:api', 'admin'])->prefix('admin')->group(function () {
    Route::get('/withdrawal-codes', [WithdrawalCodeController::class, 'adminIndex']);
    Route::post('/withdrawal-codes/{id}/complete', [WithdrawalCodeController::class, 'adminComplete']);
    Route::post('/withdrawal-codes/{id}/cancel', [WithdrawalCodeController::class, 'adminCancel']);
});