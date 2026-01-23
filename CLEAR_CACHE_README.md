# 🧹 Scripts de Nettoyage des Caches

## Fichiers créés

Trois scripts sont disponibles pour vider les caches Laravel :

1. **`clear-cache.php`** - Script PHP (recommandé, fonctionne partout)
2. **`clear-cache.sh`** - Script Bash (Linux/Mac)
3. **`clear-cache.bat`** - Script Windows Batch

## 🚀 Utilisation

### Option 1 : Script PHP (Recommandé)

```bash
php clear-cache.php
```

**Avantages :**
- Fonctionne sur tous les systèmes (Windows, Linux, Mac)
- Affiche des messages clairs
- Vérifie automatiquement la configuration CORS après

### Option 2 : Script Bash (Linux/Mac)

```bash
# Rendre exécutable (première fois seulement)
chmod +x clear-cache.sh

# Exécuter
./clear-cache.sh
```

### Option 3 : Script Windows Batch

Double-cliquez sur `clear-cache.bat` ou exécutez :
```cmd
clear-cache.bat
```

## 📋 Ce que font ces scripts

Les scripts exécutent automatiquement :

1. `php artisan config:clear` - Vide le cache de configuration
2. `php artisan cache:clear` - Vide le cache de l'application
3. `php artisan route:clear` - Vide le cache des routes
4. `php artisan view:clear` - Vide le cache des vues
5. `php artisan optimize:clear` - Vide tous les caches optimisés

## ⚠️ Important

**Exécutez toujours ces scripts après :**
- Modification du fichier `.env`
- Modification des fichiers de configuration (`config/*.php`)
- Déploiement de nouvelles versions
- Changement de configuration CORS

## 🔍 Vérification CORS

Le script PHP affiche automatiquement la configuration CORS actuelle après avoir vidé les caches, ce qui vous permet de vérifier que `ebetstream.com` est bien dans les origines autorisées.

## 💡 Astuce

Pour un accès rapide, vous pouvez créer un alias dans votre terminal :

```bash
# Linux/Mac - Ajoutez à ~/.bashrc ou ~/.zshrc
alias clear-laravel="cd /chemin/vers/ebetstream_api && php clear-cache.php"

# Windows PowerShell - Ajoutez à votre profil
function Clear-Laravel { cd C:\chemin\vers\ebetstream_api; php clear-cache.php }
```

Ensuite, vous pouvez simplement taper `clear-laravel` depuis n'importe où !



