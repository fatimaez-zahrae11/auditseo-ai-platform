# Backoffice administrateur

## Objectif

Vérifier les 13 routes admin et leur middleware `auth:sanctum -> active -> admin`.

## Cas couverts

- [x] Refus des requêtes anonymes, non-admin et admin inactif
- [x] Liste et création d’utilisateurs réguliers uniquement
- [x] Désactivation, révocation des jetons et réactivation
- [x] Auto-désactivation et désactivation du dernier admin bloquées
- [x] Supervision globale des audits et recommandations
- [x] Analytics, activité utilisateur et pagination
- [x] Journaux système et santé détaillée expurgés
- [x] Journal des actions administratives et filtres
- [x] Plafonds `per_page` et contrôles de requêtes N+1

## Fichiers PHPUnit liés

- `tests/Feature/AdminMiddlewareTest.php`
- `tests/Feature/AdminUserApiTest.php`
- `tests/Feature/AdminAuditApiTest.php`
- `tests/Feature/AdminRecommendationApiTest.php`
- `tests/Feature/AdminAnalyticsApiTest.php`
- `tests/Feature/AdminSystemApiTest.php`
- `tests/Feature/AdminActionLogApiTest.php`
- `tests/Feature/AdminUserActivityApiTest.php`

## Résultat attendu

Seuls les administrateurs actifs accèdent aux données globales. L’API ne peut pas créer d’administrateur.

## État actuel

Couvert par les tests automatisés. Dernier résultat global : 343 tests réussis, 3677 assertions.
