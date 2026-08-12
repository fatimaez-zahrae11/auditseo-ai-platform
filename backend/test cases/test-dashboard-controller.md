# Cas de test — Dashboard utilisateur

## Objectif

Vérifier que les statistiques du dashboard sont exactes et strictement limitées à l’utilisateur authentifié.

## Routes concernées

`GET /api/dashboard`.

## Préconditions

Créer plusieurs utilisateurs avec des audits dans tous les états, des problèmes et des recommandations.

## Scénarios

1. Appeler sans authentification : attendre `401`.
2. Vérifier les totaux `pending`, `running`, `completed` et `failed`.
3. Calculer la moyenne uniquement avec les audits terminés.
4. Vérifier le comportement à zéro lorsqu’aucun audit terminé n’existe.
5. Confirmer que les audits, problèmes et recommandations d’un autre utilisateur sont absents.

## Résultat attendu

Les agrégats, le dernier audit et le dernier audit terminé appartiennent exclusivement à l’utilisateur courant.

## Fichiers PHPUnit associés

- `tests/Feature/DashboardApiTest.php`

## État actuel

**Validé** — calculs et isolation multi-utilisateur couverts.
