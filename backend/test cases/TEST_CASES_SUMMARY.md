# Synthèse des cas de test du backend

## Objectif

Présenter au superviseur les scénarios de validation fonctionnelle et de sécurité du backend AuditSEO AI Platform. Ces documents sont lisibles par un humain : ils ne remplacent pas les tests automatisés.

## Routes concernées

Toutes les routes publiques, authentifiées et administratives décrites dans `API_DOCUMENTATION.md`.

## Préconditions

- Dépendances Composer installées.
- Environnement de test Laravel configuré par `phpunit.xml` avec SQLite en mémoire, cache en tableau et file synchrone.
- Aucun secret de production requis.

## Scénario

Depuis le dossier `backend`, exécuter :

```bash
php artisan test
composer audit
php artisan route:list --path=api/admin
```

Consulter ensuite les documents thématiques de ce dossier pour relier les résultats aux risques couverts.

## Résultat attendu

- Tous les tests PHPUnit réussissent.
- Composer ne signale aucun avis de sécurité.
- Les 13 routes administratives attendues sont présentes.
- Les contrôles d’accès, de propriété, de confidentialité et de disponibilité restent actifs.

## Fichiers PHPUnit associés

- `tests/Feature/*.php`
- `tests/Unit/*.php`

## État actuel

**Validé** — 343 tests réussis, 3677 assertions ; 33 routes au total ; 13 routes administratives ; aucun avis Composer.

## Index documentaire

- `test-auth-controller.md` : authentification, activation et rôles.
- `test-audit-controller.md` : API d’audit et isolation par utilisateur.
- `test-seo-crawler-service.md` : SSRF, DNS et limites du crawler.
- `test-ai-recommendation-controller.md` : API de recommandations IA.
- `test-ai-recommendation-service.md` : sécurité du fournisseur IA.
- `test-admin-backoffice.md` : backoffice administratif.
- `test-api-usage-log.md` : journalisation et confidentialité.
- `test-queue-health.md` : file d’attente, santé et planification.
- `test-resend-dependencies.md` : Resend et dépendances.
- `test-dashboard-controller.md` : agrégats utilisateur.
- `test-seo-scoring-service.md` : calcul des scores SEO.
- `test-security-rules.md` : matrice transversale de sécurité.
- `test-manual-backend-workflow.md` : contrôle manuel complémentaire.
