# Cas de test — Backoffice administratif

## Objectif

Vérifier que les vues globales et actions sensibles sont réservées aux administrateurs actifs, paginées, efficaces et expurgées.

## Routes concernées

Les 13 routes sous `/api/admin` : journaux d’actions, trois analytics, audits, recommandations, deux routes système et cinq routes utilisateurs.

## Préconditions

Créer un utilisateur régulier, un administrateur actif, un administrateur inactif, plusieurs propriétaires d’audits/recommandations et suffisamment d’éléments pour tester la pagination.

## Scénarios

1. Appeler chaque famille sans jeton, avec un non-admin puis avec un admin inactif : attendre respectivement `401`, `403`, `403`.
2. Lister les utilisateurs avec compteurs exacts, sans champs sensibles ; vérifier le plafond `per_page`.
3. Créer un utilisateur : imposer `role=user`, hacher le mot de passe et envoyer la vérification ; refuser `role=admin`.
4. Désactiver puis réactiver un utilisateur ; vérifier métadonnées, révocation des jetons, rôle inchangé et absence de nouveau jeton.
5. Bloquer l’auto-désactivation et celle du dernier administrateur actif.
6. Superviser les audits et recommandations de plusieurs utilisateurs avec filtres, e-mail propriétaire et aperçu IA limité.
7. Vérifier overview, utilisateurs actifs sur 15 minutes et classement des utilisateurs lourds avec filtres de dates.
8. Lire les journaux système bornés et expurgés, puis la santé détaillée sans exception ni secret.
9. Lister les actions administratives, appliquer tous les filtres et vérifier l’e-mail administrateur.
10. Comparer le nombre de requêtes SQL sur petits et grands jeux : absence de croissance par résultat pour les endpoints couverts.

## Résultat attendu

Seuls les administrateurs actifs accèdent aux données globales. Les réponses sont paginées, efficaces et dépourvues de mots de passe, jetons, clés, corps bruts ou traces sensibles.

## Fichiers PHPUnit associés

- `tests/Feature/AdminMiddlewareTest.php`
- `tests/Feature/AdminUserApiTest.php`
- `tests/Feature/AdminAuditApiTest.php`
- `tests/Feature/AdminRecommendationApiTest.php`
- `tests/Feature/AdminAnalyticsApiTest.php`
- `tests/Feature/AdminSystemApiTest.php`
- `tests/Feature/AdminActionLogApiTest.php`
- `tests/Feature/AdminUserActivityApiTest.php`

## État actuel

**Validé** — accès, actions, pagination, N+1 et redaction couverts.
