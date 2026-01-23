#!/bin/bash

# Script pour vider tous les caches Laravel
# Usage: ./clear-cache.sh ou bash clear-cache.sh

echo "🧹 Clearing Laravel caches..."

php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan optimize:clear

echo ""
echo "✅ All caches cleared successfully!"
echo ""
echo "Current CORS configuration:"
php artisan cors:check 2>/dev/null || echo "Run: php artisan config:clear first"



