# File d’attente et santé

## Objectif

Vérifier les transitions des jobs d’audit, les retries, les sondes de santé et la maintenance Sanctum.

## Cas couverts

- [x] Champs de file et valeurs par défaut
- [x] Transitions `pending`, `running`, `completed` et `failed`
- [x] Retry après échec temporaire
- [x] Verrou `WithoutOverlapping` et protection des états terminaux
- [x] Erreurs de job assainies
- [x] Détection des audits en attente ou en cours devenus obsolètes
- [x] `/api/health` public et générique
- [x] `/api/health/readiness` protégé et sans détails sensibles
- [x] Purge quotidienne des jetons Sanctum expirés

## Fichiers PHPUnit liés

- `tests/Feature/AuditQueueArchitectureTest.php`
- `tests/Feature/HealthCheckTest.php`
- `tests/Feature/ConsoleScheduleTest.php`

## Résultat attendu

Les jobs restent idempotents et les sondes signalent l’état des dépendances sans révéler les erreurs internes.

## État actuel

Couvert par les tests automatisés. Dernier résultat global : 343 tests réussis, 3677 assertions.
