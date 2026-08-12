# Synthèse des tests backend

## Objectif

Donner une vue rapide de la couverture automatisée. Les tests exécutables restent dans `tests/Feature` et `tests/Unit`.

## Cas couverts

- [x] Authentification, activation et rôles
- [x] Audits asynchrones, dashboard et protection IDOR
- [x] SSRF, DNS, redirections et limites de taille
- [x] Recommandations IA et sécurité du fournisseur
- [x] Backoffice et journaux administratifs
- [x] Files, santé, limitation de débit et Resend

## Fichiers PHPUnit liés

- `tests/Feature/*.php`
- `tests/Unit/*.php`

## Résultat attendu

La suite doit réussir sans dépendre de secrets ou de services externes réels. `composer audit` doit aussi rester sans avis.

## État actuel

Couvert par les tests automatisés. Dernier résultat global : 343 tests réussis, 3677 assertions.
