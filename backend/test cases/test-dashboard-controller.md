# Dashboard utilisateur

## Objectif

Vérifier les statistiques du dashboard et leur périmètre utilisateur.

## Cas couverts

- [x] Authentification obligatoire
- [x] Comptage des audits par statut
- [x] Moyenne calculée uniquement sur les audits terminés
- [x] Valeurs nulles ou à zéro sans données
- [x] Problèmes, recommandations et derniers audits limités au compte courant

## Fichiers PHPUnit liés

- `tests/Feature/DashboardApiTest.php`

## Résultat attendu

Les indicateurs sont exacts et ne contiennent aucune donnée d’un autre utilisateur.

## État actuel

Couvert par les tests automatisés. Dernier résultat global : 343 tests réussis, 3677 assertions.
