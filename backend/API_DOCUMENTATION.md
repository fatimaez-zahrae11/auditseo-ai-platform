# API AuditSEO AI Platform

## 1. Présentation rapide

API REST Laravel 12 avec authentification Sanctum. PostgreSQL stocke les données, Redis gère les files, le cache et les limites de débit. Les audits SEO sont asynchrones. L’API propose aussi des recommandations IA et un backoffice administrateur.

## 2. Authentification

| Méthode | Route | Auth | Description |
|---|---|---|---|
| `POST` | `/api/register` | Non | Crée un compte et envoie l’e-mail de vérification |
| `POST` | `/api/login` | Non | Connecte un utilisateur vérifié et actif |
| `GET` | `/api/me` | Sanctum + actif | Retourne l’utilisateur courant |
| `POST` | `/api/logout` | Sanctum + actif | Révoque le jeton courant |
| `POST` | `/api/logout-all` | Sanctum + actif | Révoque tous les jetons du compte |
| `GET` | `/api/email/verify/{id}/{hash}` | Lien signé | Vérifie l’adresse e-mail |
| `POST` | `/api/email/verification-notification` | Non | Renvoie le lien sans révéler si le compte existe |

En-tête d’authentification :

```http
Authorization: Bearer <token>
Accept: application/json
```

À retenir :

- L’inscription crée un utilisateur régulier, actif et non vérifié. Elle ne retourne aucun jeton.
- La connexion exige un compte vérifié et actif.
- Une connexion réussie révoque les anciens jetons avant d’en créer un nouveau.
- Un compte inactif ne peut pas se connecter. Ses anciens jetons sont refusés et le jeton courant est révoqué lors de la prochaine requête, si possible.
- Les erreurs principales sont `401` (non authentifié), `403` (compte inactif ou e-mail non vérifié), `422` (validation ou identifiants) et `429` (limite atteinte).

Exemple de connexion :

```json
{
  "email": "user@example.com",
  "password": "Password1"
}
```

## 3. Routes utilisateur

Les routes métier exigent Sanctum, un compte actif et un e-mail vérifié, sauf indication contraire.

| Méthode | Route | Description |
|---|---|---|
| `POST` | `/api/audits` | Crée un audit et le place dans la file |
| `GET` | `/api/audits` | Liste les audits de l’utilisateur |
| `GET` | `/api/audits/{audit}` | Retourne un audit de l’utilisateur |
| `GET` | `/api/dashboard` | Retourne les statistiques de l’utilisateur |
| `POST` | `/api/audits/{audit}/recommendations` | Génère une recommandation IA |
| `GET` | `/api/audits/{audit}/recommendations` | Liste l’historique paginé des recommandations |
| `GET` | `/api/health` | Santé publique et générique |
| `GET` | `/api/health/readiness` | État protégé des dépendances |

Notes :

- `POST /api/audits` retourne `202 Accepted`. Un worker traite ensuite l’audit.
- Statuts d’audit : `pending`, `running`, `completed`, `failed`.
- Les audits, le dashboard et les recommandations sont limités à l’utilisateur authentifié. Une ressource appartenant à un autre compte retourne `404`.
- La génération IA exige un audit `completed`. Sinon, l’API retourne `409`.
- L’historique des recommandations accepte `page` et `per_page` ; maximum 50.
- Les erreurs du crawler et du fournisseur IA sont assainies.
- `/api/health` est public. `/api/health/readiness` est protégé et ne retourne que des états sûrs.

Exemple de création d’audit :

```json
{
  "url": "https://example.com"
}
```

## 4. Routes admin

Toutes les routes admin utilisent, dans cet ordre :

```text
auth:sanctum -> active -> admin
```

| Méthode | Route | Description |
|---|---|---|
| `GET` | `/api/admin/action-logs` | Liste les actions administratives |
| `GET` | `/api/admin/analytics/active-users` | Utilisateurs actifs sur les 15 dernières minutes |
| `GET` | `/api/admin/analytics/heavy-users` | Classement des utilisateurs par usage |
| `GET` | `/api/admin/analytics/overview` | Indicateurs globaux |
| `GET` | `/api/admin/audits` | Liste tous les audits avec filtres |
| `GET` | `/api/admin/recommendations` | Liste globale avec aperçu du texte |
| `GET` | `/api/admin/system/health-detailed` | État opérationnel détaillé et filtré |
| `GET` | `/api/admin/system/logs` | Dernières lignes expurgées du journal Laravel |
| `GET` | `/api/admin/users` | Liste les utilisateurs et leurs compteurs |
| `POST` | `/api/admin/users` | Crée un utilisateur régulier |
| `GET` | `/api/admin/users/{user}/activity` | Résume l’activité d’un utilisateur |
| `PATCH` | `/api/admin/users/{user}/deactivate` | Désactive un utilisateur et révoque ses jetons |
| `PATCH` | `/api/admin/users/{user}/reactivate` | Réactive un utilisateur |

Règles :

- La promotion admin se fait uniquement avec `php artisan make:admin {email}`.
- L’API ne peut pas créer d’administrateur.
- Un administrateur ne peut pas se désactiver lui-même.
- Le dernier administrateur actif ne peut pas être désactivé.
- Les listes sont paginées avec des plafonds sur `per_page`.
- Les actions sensibles sont enregistrées dans `admin_action_logs`.

## 5. Sécurité importante

- Limites de débit globales et spécifiques aux opérations sensibles.
- Protection IDOR : les routes utilisateur filtrent les données par utilisateur authentifié.
- Protection SSRF avec validation des URL, épinglage DNS et contrôle de chaque redirection.
- Blocage des adresses locales, privées et spéciales en IPv4 et IPv6.
- Limites de taille sur les réponses HTML, compressées, robots, sitemaps, liens et réponses IA.
- Fournisseur IA en HTTPS avec liste exacte des hôtes autorisés ; redirections désactivées.
- `access_logs` ne stocke ni corps, ni query string, ni cookie, ni en-tête Authorization.
- `admin_action_logs` supprime les métadonnées sensibles.
- Le webhook entrant Resend inutilisé `/resend/webhook` est désactivé ; l’envoi sortant reste disponible.
- `league/commonmark` est fixé en `2.10.0` et `composer audit` est propre.

## 6. Résultats vérifiés

| Commande | Résultat |
|---|---|
| `php artisan test` | 343 tests, 3677 assertions |
| `composer audit` | Aucun avis |
| `php artisan route:list` | 33 routes |
| `php artisan route:list --path=api/admin` | 13 routes |
