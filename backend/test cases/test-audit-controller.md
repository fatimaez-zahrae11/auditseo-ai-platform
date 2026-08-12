# Cas de test — API d’audit SEO

## Objectif

Vérifier la création asynchrone, les états d’audit et l’isolation stricte des données de chaque utilisateur.

## Routes concernées

`POST /api/audits`, `GET /api/audits`, `GET /api/audits/{audit}`, `GET /api/dashboard`.

## Préconditions

Créer au moins deux utilisateurs vérifiés avec des domaines et audits distincts. Utiliser une file simulée ou synchrone selon le scénario et des réponses HTTP de crawler contrôlées.

## Scénarios

1. Appeler les routes sans authentification : attendre `401`.
2. Soumettre une URL publique valide : attendre `202`, un audit `pending`, une URL de polling et un job mis en file.
3. Lister les audits avec l’utilisateur A : aucun audit de l’utilisateur B ne doit apparaître.
4. Lire l’audit de B avec le jeton de A : attendre `404` afin de conserver la protection IDOR.
5. Soumettre une URL invalide ou dangereuse : attendre `422` avant tout appel réseau.
6. Vérifier les représentations des statuts `pending`, `running`, `completed` et `failed`.
7. Provoquer un échec du crawler ou de la mise en file : retourner un message générique sans raison interne sensible.
8. Consulter le dashboard de A : tous les totaux, scores moyens et derniers audits doivent être limités aux données de A.

## Résultat attendu

La création est asynchrone et retourne `202`. Les listes, détails et agrégats restent limités à l’utilisateur authentifié. Les erreurs d’échec sont assainies.

## Fichiers PHPUnit associés

- `tests/Feature/AuditApiTest.php`
- `tests/Feature/StoreAuditRequestTest.php`
- `tests/Feature/DashboardApiTest.php`
- `tests/Unit/SeoScoringServiceTest.php`

## État actuel

**Validé** — couverture automatisée réussie, notamment les tests IDOR.
