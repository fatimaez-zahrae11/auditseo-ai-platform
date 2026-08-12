# API AuditSEO AI Platform

## 1. Introduction

AuditSEO AI Platform est un backend REST construit avec Laravel 12. L’API utilise :

- Laravel Sanctum avec jetons Bearer pour l’authentification ;
- PostgreSQL comme base de données cible en développement réel et en production ;
- Redis pour les files d’attente, le cache et la limitation de débit ;
- des workers de file d’attente pour exécuter les audits SEO de manière asynchrone ;
- Resend pour l’envoi des notifications de vérification d’adresse e-mail.

URL de base typique : `https://api.example.com/api`.

Pour une route protégée :

```http
Accept: application/json
Authorization: Bearer <jeton-sanctum>
```

Les réponses sont en JSON. Les erreurs internes, raisons techniques sensibles, clés, jetons et traces complètes ne sont jamais destinés aux réponses publiques.

## 2. Principes d’authentification

- L’inscription crée un utilisateur régulier (`role=user`), actif et non vérifié.
- L’inscription n’émet aucun jeton API.
- L’utilisateur doit vérifier son adresse e-mail avant de pouvoir se connecter.
- La connexion est refusée à un compte inactif.
- Une connexion réussie révoque les anciens jetons puis émet un nouveau jeton Sanctum avec expiration.
- Si un compte est désactivé après émission d’un jeton, la prochaine requête authentifiée reçoit `403` et le jeton courant est révoqué lorsque cela est possible.
- Le rôle et les champs de blocage ne peuvent pas être injectés par les routes publiques.

## 3. Authentification

### `POST /api/register`

Authentification : aucune.

Requête :

```json
{
  "name": "Alice Martin",
  "email": "alice@example.com",
  "password": "MotDePasse1"
}
```

Succès — `201 Created` :

```json
{
  "message": "Registration successful. Please verify your email before logging in."
}
```

La réponse ne contient ni utilisateur sensible ni jeton. Une notification de vérification est envoyée. Erreurs principales : `422` pour les données invalides ou une adresse déjà utilisée, `429` en cas de dépassement de débit.

### `POST /api/login`

Authentification : aucune. Le compte doit être actif et son adresse e-mail vérifiée.

Requête :

```json
{
  "email": "alice@example.com",
  "password": "MotDePasse1"
}
```

Succès — `200 OK` :

```json
{
  "message": "Login successful.",
  "user": {
    "id": 12,
    "name": "Alice Martin",
    "email": "alice@example.com",
    "role": "user",
    "is_active": true
  },
  "token": "<jeton-affiché-une-seule-fois>"
}
```

Avant de créer le nouveau jeton, l’API révoque tous les anciens jetons de l’utilisateur. Erreurs principales :

- `422 {"message":"Invalid credentials."}` pour un identifiant ou mot de passe incorrect, sans révéler si le compte existe ;
- `403 {"message":"Email verification is required before login."}` si l’adresse n’est pas vérifiée ;
- `403 {"message":"Account disabled"}` si le compte est inactif ;
- `429` si la limite par adresse e-mail ou adresse IP est dépassée.

### `GET /api/me`

Authentification : Bearer Sanctum et compte actif. La vérification e-mail n’est pas requise par cette route.

Succès — `200 OK` :

```json
{
  "user": {
    "id": 12,
    "name": "Alice Martin",
    "email": "alice@example.com",
    "role": "user",
    "is_active": true
  }
}
```

Erreurs principales : `401` sans jeton valide ; `403 {"message":"Account disabled"}` si le compte a été désactivé.

### `POST /api/logout`

Authentification : Bearer Sanctum et compte actif.

Corps : aucun.

Succès — `200 OK` :

```json
{
  "message": "Logout successful."
}
```

Le jeton courant est révoqué. Erreurs principales : `401` sans jeton valide, `403` si le compte est désactivé.

### `POST /api/logout-all`

Authentification : Bearer Sanctum et compte actif.

Corps : aucun.

Succès — `200 OK` :

```json
{
  "message": "All sessions logged out successfully."
}
```

Tous les jetons Sanctum de l’utilisateur sont révoqués. Erreurs principales : `401` sans jeton valide, `403` si le compte est désactivé.

### `GET /api/email/verify/{id}/{hash}`

Authentification : aucune, mais l’URL doit porter une signature Laravel valide et non expirée.

Exemple :

```http
GET /api/email/verify/12/0123456789abcdef...?expires=...&signature=...
```

Succès — `200 OK` :

```json
{
  "message": "Email verified successfully. You may now log in."
}
```

Erreurs principales : `403` pour une signature, une expiration ou un hash incorrect ; `404` si l’utilisateur n’existe pas.

### `POST /api/email/verification-notification`

Authentification : aucune. La réponse est volontairement identique que l’adresse existe ou non.

Requête :

```json
{
  "email": "alice@example.com"
}
```

Succès — `200 OK` :

```json
{
  "message": "If the email is registered and unverified, a verification link has been sent."
}
```

Erreurs principales : `422` si l’adresse est invalide, `429` si une limite anti-abus par e-mail ou IP est dépassée.

## 4. Audits SEO de l’utilisateur

Toutes ces routes exigent un jeton Sanctum, un compte actif et une adresse e-mail vérifiée. Les requêtes sont strictement limitées aux audits appartenant à l’utilisateur authentifié. Une ressource appartenant à un autre utilisateur est traitée comme introuvable.

Les statuts possibles sont `pending`, `running`, `completed` et `failed`.

### `POST /api/audits`

Requête :

```json
{
  "url": "https://www.example.com/"
}
```

Succès — `202 Accepted` :

```json
{
  "message": "Audit queued for processing.",
  "audit": {
    "id": 42,
    "status": "pending",
    "requested_url": "https://www.example.com/"
  },
  "poll_url": "/api/audits/42"
}
```

L’audit n’est pas exécuté pendant la requête HTTP : un job est envoyé à la file et un worker le fait évoluer de `pending` vers `running`, puis `completed` ou `failed`. Erreurs principales : `401`, `403`, `422` pour une URL invalide ou dangereuse, `429` après 10 créations par heure, `503` si la mise en file échoue.

### `GET /api/audits`

Retourne uniquement les audits de l’utilisateur, du plus récent au plus ancien, avec une pagination fixe de 20 éléments.

Succès — `200 OK` :

```json
{
  "audits": [
    {
      "id": 42,
      "requested_url": "https://www.example.com/",
      "final_url": "https://example.com/",
      "status": "completed",
      "global_score": 84,
      "technical_score": 80,
      "content_score": 86,
      "links_score": 81,
      "performance_score": 89,
      "created_at": "2026-08-12T10:00:00.000000Z"
    }
  ],
  "pagination": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 1
  }
}
```

Erreurs principales : `401`, `403`, `429`.

### `GET /api/audits/{audit}`

Retourne le détail, le domaine et les problèmes détectés pour un audit appartenant à l’utilisateur.

Succès — `200 OK` :

```json
{
  "audit": {
    "id": 42,
    "status": "completed",
    "requested_url": "https://www.example.com/",
    "final_url": "https://example.com/",
    "issues": []
  }
}
```

Erreurs principales : `401`, `403`, `404` si l’audit n’existe pas ou appartient à un autre utilisateur. Les raisons techniques d’échec ne sont pas exposées par l’API normale.

### `GET /api/dashboard`

Retourne des agrégats calculés uniquement sur les données de l’utilisateur authentifié : nombre total d’audits, ventilation par statut, moyenne des scores terminés, problèmes, recommandations et derniers audits.

Succès — `200 OK` :

```json
{
  "total_audits": 8,
  "completed_audits": 5,
  "pending_audits": 1,
  "running_audits": 1,
  "failed_audits": 1,
  "average_global_score": 78,
  "total_issues": 14,
  "total_ai_recommendations": 3,
  "latest_audit": {},
  "latest_completed_audit": {}
}
```

Erreurs principales : `401`, `403`, `429`.

## 5. Recommandations IA

Ces routes exigent l’authentification, un compte actif, une adresse vérifiée et la propriété de l’audit.

### `POST /api/audits/{audit}/recommendations`

Corps : aucun. L’audit doit avoir le statut `completed`.

Succès — `201 Created` :

```json
{
  "message": "AI recommendation generated successfully.",
  "recommendation": {
    "id": 9,
    "audit_id": 42,
    "generated_text": "Priorisez les corrections techniques...",
    "created_at": "2026-08-12T10:15:00.000000Z"
  }
}
```

Erreurs principales : `401`, `403`, `404` pour un audit absent ou non possédé, `409` si l’audit n’est pas terminé, `429` après 5 générations par minute, `502` si le fournisseur IA échoue. Les détails fournisseur et la clé API ne sont jamais renvoyés.

### `GET /api/audits/{audit}/recommendations`

Paramètres : `page` et `per_page`. La taille par défaut est 20 et le maximum est 50.

Succès — `200 OK` :

```json
{
  "recommendations": [
    {
      "id": 9,
      "audit_id": 42,
      "generated_text": "Priorisez les corrections techniques..."
    }
  ],
  "pagination": {
    "current_page": 1,
    "last_page": 1,
    "per_page": 20,
    "total": 1
  }
}
```

L’historique est paginé et trié du plus récent au plus ancien. Erreurs principales : `401`, `403`, `404`, `429`.

## 6. Santé de l’application

### `GET /api/health`

Authentification : aucune. Cette sonde publique reste générique.

Succès — `200 OK` :

```json
{
  "status": "ok"
}
```

Si la base n’est pas disponible, la réponse est `503` avec `{"status":"degraded"}` sans détail d’exception ni secret.

### `GET /api/health/readiness`

Authentification : Bearer Sanctum, compte actif et adresse vérifiée.

Cette sonde vérifie de manière sûre la base, Redis lorsque configuré, et l’état de la file d’audits. Elle peut signaler des audits `pending` ou `running` obsolètes sans exposer de DSN, mot de passe ou exception brute.

Succès : `200` avec `status=ready`. Indisponibilité : `503` avec `status=not_ready`. Erreurs d’accès : `401` ou `403`.

## 7. API d’administration

Les 13 routes ci-dessous sont regroupées sous `/api/admin` et utilisent exactement, dans cet ordre :

```text
auth:sanctum -> active -> admin
```

Elles permettent des vues globales uniquement parce qu’elles sont réservées aux administrateurs actifs authentifiés. Une requête non authentifiée reçoit `401`, un utilisateur régulier ou un administrateur inactif reçoit `403`.

Les listes utilisent une pagination et des plafonds sûrs (`per_page` généralement 20 par défaut, maximum 100). Les relations sont préchargées ou agrégées pour éviter les requêtes N+1. Les réponses sont façonnées et expurgées.

| Méthode et route | Fonction | Paramètres principaux |
|---|---|---|
| `GET /api/admin/action-logs` | Journal paginé des actions administratives sensibles | `admin_user_id`, `action`, `target_type`, `target_id`, `created_from`, `created_to`, `page`, `per_page` |
| `GET /api/admin/analytics/active-users` | Utilisateurs ayant eu une activité API dans les 15 dernières minutes ; ce n’est pas une présence temps réel | `page`, `per_page` |
| `GET /api/admin/analytics/heavy-users` | Classement des utilisateurs par usage | `from`, `to`, `page`, `per_page` |
| `GET /api/admin/analytics/overview` | Totaux utilisateurs, audits, recommandations et requêtes | aucun |
| `GET /api/admin/audits` | Audits de tous les utilisateurs | `status`, `user_id`, `search`, `created_from`, `created_to`, `page`, `per_page` |
| `GET /api/admin/recommendations` | Recommandations de tous les utilisateurs, avec aperçu limité à 300 caractères | `user_id`, `audit_id`, `search`, `created_from`, `created_to`, `page`, `per_page` |
| `GET /api/admin/system/health-detailed` | État opérationnel détaillé mais expurgé | aucun |
| `GET /api/admin/system/logs` | Dernières lignes expurgées de `storage/logs/laravel.log` uniquement | `lines`, défaut 100, maximum 200 |
| `GET /api/admin/users` | Liste des utilisateurs et compteurs d’audits/recommandations | `page`, `per_page` |
| `POST /api/admin/users` | Création d’un utilisateur régulier à vérifier | `name`, `email`, `password` |
| `GET /api/admin/users/{user}/activity` | Dernière activité, dernière IP, volumes et 10 routes récentes | aucun |
| `PATCH /api/admin/users/{user}/deactivate` | Désactivation, métadonnées de blocage et révocation de tous les jetons | `blocked_reason` facultatif |
| `PATCH /api/admin/users/{user}/reactivate` | Réactivation et nettoyage des métadonnées de blocage | aucun |

Exemple de création administrative :

```json
{
  "name": "Nouvel utilisateur",
  "email": "nouveau@example.com",
  "password": "MotDePasse1",
  "role": "admin"
}
```

Le champ `role` est rejeté par la validation : l’API ne peut créer que `role=user`. La promotion passe exclusivement par la console :

```bash
php artisan make:admin admin@example.com
```

La désactivation de son propre compte est bloquée. La désactivation du dernier administrateur actif est également bloquée. La réactivation conserve le rôle et n’émet aucun jeton.

Les actions sensibles suivantes sont enregistrées dans `admin_action_logs` : création, désactivation et réactivation d’un utilisateur, consultation des journaux système et consultation de la santé détaillée. Les métadonnées sensibles sont supprimées avant stockage, et une panne de journalisation ne casse pas l’action principale.

## 8. Codes d’erreur courants

| Code | Signification |
|---|---|
| `401` | Jeton absent, invalide ou expiré |
| `403` | Compte inactif, e-mail non vérifié ou droits insuffisants |
| `404` | Route ou ressource absente ; également utilisé pour protéger la propriété des ressources |
| `409` | État incompatible, notamment audit non terminé pour une recommandation |
| `422` | Échec de validation ou identifiants invalides |
| `429` | Limite de débit dépassée |
| `502` | Fournisseur IA indisponible, avec message générique |
| `503` | Dépendance ou service interne indisponible, sans détail sensible |

## 9. Sécurité

### Authentification et limitation de débit

- Jetons Sanctum Bearer avec expiration et révocation.
- Vérification e-mail obligatoire avant connexion et accès aux fonctionnalités métier.
- Comptes inactifs refusés à la connexion et bloqués sur leurs anciens jetons.
- Limiteurs globaux et spécialisés pour les routes publiques, la connexion, l’inscription, le renvoi de vérification, les audits et les recommandations IA.
- Messages de connexion uniformes et vérification de mot de passe factice pour réduire l’énumération des comptes.

### Protection du crawler SEO

- Seules les URL HTTP(S) autorisées par la politique publique sont acceptées.
- Blocage des adresses locales, privées, link-local et autres plages spéciales IPv4/IPv6.
- Refus des identifiants intégrés aux URL et des ports non autorisés.
- Résolution DNS contrôlée et épinglage DNS vers l’adresse validée.
- Revalidation de chaque redirection et des ressources secondaires.
- Limites de taille appliquées aux réponses HTML, compressées, robots, sitemaps et liens vérifiés.
- Échec fermé si l’épinglage DNS sécurisé n’est pas disponible.

### Fournisseur IA

- URL HTTPS obligatoire et liste d’hôtes exacts autorisés.
- Redirections fournisseur désactivées.
- Prompt réduit aux signaux SEO nécessaires et URL assainies.
- Limites sur les octets de réponse et la longueur du texte généré.
- Erreurs JSON, réseau et fournisseur transformées en réponses génériques.
- Clés et réponses brutes du fournisseur absentes des réponses et journaux d’usage.

### Journaux et confidentialité

- `access_logs` stocke uniquement utilisateur éventuel, IP, méthode, chemin sans query string, code HTTP, user-agent borné et date.
- Aucun corps, mot de passe, cookie, en-tête Authorization, jeton, clé API ou valeur `.env` n’est stocké.
- `admin_action_logs` ne reçoit que des métadonnées explicitement choisies ; les clés sensibles sont supprimées récursivement.
- Les échecs de journalisation sont isolés et ne modifient pas la réponse métier.
- La lecture administrative du journal Laravel est bornée à un fichier fixe, limitée et expurgée.

### Resend et dépendances

- Le webhook entrant inutilisé `POST /resend/webhook` est désactivé et retourne `404`.
- Le transport sortant Resend reste disponible pour les e-mails de vérification.
- `league/commonmark` est verrouillé en version `2.10.0`.
- `composer audit` ne signale actuellement aucun avis de sécurité.

## 10. Diagnostics vérifiés

État vérifié après les correctifs de dépendance et de webhook :

```text
php artisan test
343 tests réussis, 3677 assertions

composer audit
Aucun avis de sécurité

php artisan route:list
33 routes

php artisan route:list --path=api/admin
13 routes administratives
```

Ces nombres décrivent l’état de référence vérifié. Toute évolution de routes ou de tests doit mettre cette section à jour.
