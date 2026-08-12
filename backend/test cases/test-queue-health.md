# Cas de test — File d’attente, santé et planification

## Objectif

Vérifier le cycle de vie robuste des jobs d’audit, les sondes de santé sûres et la maintenance planifiée des jetons.

## Routes concernées

`POST /api/audits`, `GET /api/audits/{audit}`, `GET /api/health`, `GET /api/health/readiness` et la planification console.

## Préconditions

Créer des audits jeunes et obsolètes dans différents états, simuler succès/échecs de base et Redis, puis exécuter les jobs avec plusieurs tentatives.

## Scénarios

1. Vérifier les champs de file et leurs valeurs sûres par défaut.
2. Observer les transitions `pending -> running -> completed` et la transition terminale vers `failed`.
3. Échouer à la première tentative puis réussir au retry sans réinitialiser indûment l’horodatage de démarrage.
4. Vérifier le verrou `WithoutOverlapping` et l’absence d’écrasement d’un audit déjà terminal.
5. Contrôler que les exceptions de job et `failed_jobs` sont assainies.
6. Détecter les audits `pending` ou `running` dépassant les seuils dans la readiness, sans marquer les audits récents.
7. Vérifier que `/api/health` reste public et générique, tandis que la readiness est authentifiée, vérifiée et sûre en cas de panne.
8. Vérifier la planification quotidienne de purge des jetons Sanctum expirés.

## Résultat attendu

Les transitions sont idempotentes, les retries sûrs, les états obsolètes détectés et aucune sonde ne révèle de détail d’infrastructure sensible.

## Fichiers PHPUnit associés

- `tests/Feature/AuditQueueArchitectureTest.php`
- `tests/Feature/HealthCheckTest.php`
- `tests/Feature/ConsoleScheduleTest.php`

## État actuel

**Validé** — architecture de file, readiness et planification couvertes.
