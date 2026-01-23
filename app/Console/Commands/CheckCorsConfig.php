<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckCorsConfig extends Command
{
    protected $signature = 'cors:check';
    protected $description = 'Check current CORS configuration';

    public function handle()
    {
        $this->info('=== CORS Configuration Check ===');
        $this->newLine();

        // Check .env
        $corsOrigins = env('CORS_ALLOWED_ORIGINS');
        $this->line('CORS_ALLOWED_ORIGINS from .env:');
        $this->line($corsOrigins ?: '(not set)');
        $this->newLine();

        // Check config
        $configOrigins = config('cors.allowed_origins');
        $this->line('Current CORS allowed_origins (from config):');
        if (is_array($configOrigins)) {
            foreach ($configOrigins as $origin) {
                $this->line("  - {$origin}");
            }
        } else {
            $this->line("  {$configOrigins}");
        }
        $this->newLine();

        // Check if ebetstream.com is included
        $hasEbetstream = false;
        if (is_array($configOrigins)) {
            foreach ($configOrigins as $origin) {
                if (str_contains($origin, 'ebetstream.com')) {
                    $hasEbetstream = true;
                    break;
                }
            }
        }

        if ($hasEbetstream) {
            $this->info('✓ ebetstream.com is in allowed origins');
        } else {
            $this->error('✗ ebetstream.com is NOT in allowed origins');
            $this->newLine();
            $this->warn('To fix this:');
            $this->line('1. Add to .env: CORS_ALLOWED_ORIGINS=https://ebetstream.com,https://www.ebetstream.com');
            $this->line('2. Run: php artisan config:clear');
        }

        $this->newLine();
        $this->line('Supports credentials: ' . (config('cors.supports_credentials') ? 'true' : 'false'));

        return 0;
    }
}



