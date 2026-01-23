@echo off
echo ========================================
echo   Configuration de l'API Locale
echo ========================================
echo.

REM Vérifier PHP
php -v >nul 2>&1
if errorlevel 1 (
    echo ERREUR: PHP n'est pas installe!
    pause
    exit /b 1
)

REM Vérifier Composer
composer --version >nul 2>&1
if errorlevel 1 (
    echo ERREUR: Composer n'est pas installe!
    pause
    exit /b 1
)

echo [1/6] Installation des dependances Composer...
composer install

echo [2/6] Configuration du fichier .env...
if not exist .env (
    if exist .env.example (
        copy .env.example .env
        echo Fichier .env cree a partir de .env.example
    ) else (
        echo Creation d'un fichier .env de base...
        (
            echo APP_NAME=eBetStream
            echo APP_ENV=local
            echo APP_KEY=
            echo APP_DEBUG=true
            echo APP_URL=http://localhost:8000
            echo.
            echo DB_CONNECTION=sqlite
            echo DB_DATABASE=database/database.sqlite
            echo.
            echo CORS_ALLOWED_ORIGINS=http://localhost:5173,http://localhost:5174
            echo SANCTUM_STATEFUL_DOMAINS=localhost,127.0.0.1
        ) > .env
        echo Fichier .env cree avec la configuration de base
    )
) else (
    echo Le fichier .env existe deja
)

echo [3/6] Generation de la cle d'application...
php artisan key:generate

echo [4/6] Creation de la base de donnees SQLite...
if not exist database\database.sqlite (
    type nul > database\database.sqlite
    echo Base de donnees SQLite creee
) else (
    echo Base de donnees SQLite existe deja
)

echo [5/6] Execution des migrations...
php artisan migrate --force

echo [6/6] Execution des seeders...
echo Voulez-vous executer les seeders pour creer des donnees de test? (O/N)
set /p run_seeders=
if /i "%run_seeders%"=="O" (
    php artisan db:seed
    echo Seeders executes avec succes!
) else (
    echo Seeders ignores
)

echo.
echo ========================================
echo   Configuration terminee!
echo ========================================
echo.
echo Pour demarrer l'API, executez: start-api.bat
echo Ou manuellement: php artisan serve
echo.
pause


