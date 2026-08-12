# API d’audit SEO

## Objectif

Vérifier la création asynchrone des audits, leurs états et l’isolation des données par utilisateur.

## Cas couverts

- [x] Accès refusé sans authentification
- [x] Création d’un audit avec réponse `202` et mise en file
- [x] Statuts `pending`, `running`, `completed` et `failed`
- [x] Liste limitée aux audits de l’utilisateur courant
- [x] Détail limité au propriétaire ; autre propriétaire traité en `404`
- [x] Rejet des URL invalides ou dangereuses avant le crawl
- [x] Erreurs d’audit assainies
- [x] Dashboard limité aux données de l’utilisateur

## Fichiers PHPUnit liés

- `tests/Feature/AuditApiTest.php`
- `tests/Feature/StoreAuditRequestTest.php`
- `tests/Feature/DashboardApiTest.php`
- `tests/Unit/SeoScoringServiceTest.php`

## Résultat attendu

Le traitement reste asynchrone et un utilisateur ne voit jamais les audits d’un autre compte.

## État actuel

Couvert par les tests automatisés. Dernier résultat global : 343 tests réussis, 3677 assertions.
