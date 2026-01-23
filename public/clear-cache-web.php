<?php
/**
 * Script pour vider les caches Laravel via navigateur
 * ⚠️ SUPPRIMEZ CE FICHIER APRÈS UTILISATION pour des raisons de sécurité
 * 
 * Usage: https://api.ebetstream.com/clear-cache-web.php
 */

// Vérifier que nous sommes dans le bon répertoire
$basePath = dirname(__DIR__);
if (!file_exists($basePath . '/artisan')) {
    die('Error: artisan file not found.');
}

chdir($basePath);

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>Clear Laravel Cache</title>
    <style>
        body { font-family: monospace; padding: 20px; background: #1a1a1a; color: #0f0; }
        h2 { color: #0f0; }
        .success { color: #0f0; }
        .warning { color: #ff0; }
        .error { color: #f00; }
        pre { background: #000; padding: 15px; border-radius: 5px; }
    </style>
</head>
<body>
    <h2>🧹 Clearing Laravel Caches...</h2>
    <pre>
<?php

$commands = [
    'config:clear',
    'cache:clear',
    'route:clear',
    'view:clear',
    'optimize:clear',
];

$allSuccess = true;

foreach ($commands as $command) {
    echo "Running: php artisan {$command}\n";
    $output = [];
    $returnVar = 0;
    exec("php artisan {$command} 2>&1", $output, $returnVar);
    
    if ($returnVar === 0) {
        echo "<span class='success'>✅ {$command} - Success</span>\n";
        if (!empty($output)) {
            foreach ($output as $line) {
                echo "   {$line}\n";
            }
        }
    } else {
        echo "<span class='warning'>⚠️  {$command} - Warning (code: {$returnVar})</span>\n";
        if (!empty($output)) {
            foreach ($output as $line) {
                echo "   {$line}\n";
            }
        }
        $allSuccess = false;
    }
    echo "\n";
}

echo "\n";
if ($allSuccess) {
    echo "<span class='success'>✅ All caches cleared successfully!</span>\n\n";
} else {
    echo "<span class='warning'>⚠️  Some commands had warnings. Check above.</span>\n\n";
}

// Afficher la configuration CORS
echo "Current CORS configuration:\n";
echo "─────────────────────────────\n";
try {
    require $basePath . '/vendor/autoload.php';
    $app = require_once $basePath . '/bootstrap/app.php';
    
    $origins = config('cors.allowed_origins');
    $envOrigins = env('CORS_ALLOWED_ORIGINS');
    
    echo "CORS_ALLOWED_ORIGINS from .env: " . ($envOrigins ?: '(not set)') . "\n\n";
    echo "Current allowed_origins:\n";
    if (is_array($origins)) {
        foreach ($origins as $origin) {
            echo "  - {$origin}\n";
        }
    } else {
        echo "  {$origins}\n";
    }
    echo "\nSupports credentials: " . (config('cors.supports_credentials') ? 'true' : 'false') . "\n";
    
    // Vérifier si ebetstream.com est inclus
    $hasEbetstream = false;
    if (is_array($origins)) {
        foreach ($origins as $origin) {
            if (str_contains($origin, 'ebetstream.com')) {
                $hasEbetstream = true;
                break;
            }
        }
    }
    
    echo "\n";
    if ($hasEbetstream) {
        echo "<span class='success'>✅ ebetstream.com is in allowed origins</span>\n";
    } else {
        echo "<span class='error'>❌ ebetstream.com is NOT in allowed origins</span>\n";
        echo "\nTo fix: Add to .env:\n";
        echo "CORS_ALLOWED_ORIGINS=https://ebetstream.com,https://www.ebetstream.com\n";
    }
} catch (Exception $e) {
    echo "<span class='error'>Error loading config: " . $e->getMessage() . "</span>\n";
}

?>
    </pre>
    <hr>
    <p><strong>⚠️ IMPORTANT : Supprimez ce fichier maintenant pour des raisons de sécurité !</strong></p>
    <p>Pour le supprimer : Via cPanel File Manager ou FTP, supprimez <code>public/clear-cache-web.php</code></p>
</body>
</html>

