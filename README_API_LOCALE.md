# 🚀 Démarrage Rapide - API Locale

## ⚡ Configuration Rapide (Windows)

### Option 1 : Script Automatique (Recommandé)

1. **Double-cliquez sur** `setup-local.bat`
   - Installe les dépendances
   - Crée le fichier `.env`
   - Configure la base de données
   - Exécute les migrations

2. **Double-cliquez sur** `start-api.bat`
   - Démarre le serveur API sur `http://localhost:8000`

### Option 2 : Configuration Manuelle

```bash
# 1. Installer les dépendances
composer install

# 2. Créer le fichier .env
copy .env.example .env

# 3. Générer la clé d'application
php artisan key:generate

# 4. Créer la base de données SQLite
type nul > database\database.sqlite

# 5. Exécuter les migrations
php artisan migrate

# 6. (Optionnel) Exécuter les seeders
php artisan db:seed

# 7. Démarrer le serveur
php artisan serve
```

---

## 📋 État Actuel

✅ **PHP 8.3.14** installé  
✅ **Composer** disponible  
⚠️ **Fichier .env** à créer (utilisez `.env.example` comme modèle)  
⚠️ **Base de données SQLite** à créer  

---

## 🔧 Prochaines Étapes

1. **Créer le fichier .env** :
   ```bash
   copy .env.example .env
   ```

2. **Générer la clé d'application** :
   ```bash
   php artisan key:generate
   ```

3. **Créer la base de données** :
   ```bash
   type nul > database\database.sqlite
   ```

4. **Exécuter les migrations** :
   ```bash
   php artisan migrate
   ```

5. **Exécuter les seeders** (pour créer l'admin) :
   ```bash
   php artisan db:seed
   ```

6. **Démarrer l'API** :
   ```bash
   php artisan serve
   ```

L'API sera accessible sur : **http://localhost:8000**

---

## 🧪 Tester l'API

Ouvrez dans votre navigateur :
- **Test** : http://localhost:8000/api/test
- **Catégories** : http://localhost:8000/api/game-categories

---

## 📝 Utilisateur Admin par Défaut

Après avoir exécuté les seeders :
- **Email** : `admin@ebetstream.com`
- **Mot de passe** : `admin123`

---

## 📚 Documentation Complète

Consultez `GUIDE_API_LOCALE.md` pour la documentation complète.

---

## 🐛 Problèmes Courants

### "No application encryption key"
```bash
php artisan key:generate
```

### "SQLSTATE[HY000] [2002] No connection could be made"
- Vérifiez que MySQL est démarré dans WAMP
- Ou utilisez SQLite (plus simple)

### Erreur CORS depuis le frontend
Vérifiez que dans `.env` :
```env
CORS_ALLOWED_ORIGINS=http://localhost:5173,http://localhost:5174
```

---

**Prêt à continuer les modifications ! 🎯**


