# Règles de sécurité transversales

## Objectif

Regrouper les contrôles communs aux routes publiques, utilisateur et admin.

## Cas couverts

- [x] Réponses `401`, `403` et `404` selon le contexte
- [x] Protection IDOR sur audits, recommandations et dashboard
- [x] Middleware admin dans l’ordre `auth:sanctum -> active -> admin`
- [x] Limites de débit globales et spécifiques
- [x] SSRF, DNS, redirections et tailles de réponse
- [x] Erreurs API sans trace ni détail sensible
- [x] Absence de mots de passe, jetons et clés dans les réponses et logs

## Fichiers PHPUnit liés

- `tests/Feature/ApiExceptionHandlingTest.php`
- `tests/Feature/GlobalRateLimitTest.php`
- `tests/Feature/AuthenticatedRateLimitingTest.php`
- `tests/Feature/AuditApiTest.php`
- `tests/Feature/AdminMiddlewareTest.php`

## Résultat attendu

Les protections restent cohérentes sur toutes les familles de routes et les erreurs ne révèlent pas d’information sensible.

## État actuel

Couvert par les tests automatisés. Dernier résultat global : 343 tests réussis, 3677 assertions.
