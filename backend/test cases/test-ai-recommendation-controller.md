# Cas de test — API de recommandations IA

## Objectif

Vérifier les contrôles d’accès, l’état préalable de l’audit, la pagination et la gestion sûre des erreurs du fournisseur.

## Routes concernées

`POST /api/audits/{audit}/recommendations`, `GET /api/audits/{audit}/recommendations`, `GET /api/admin/recommendations`.

## Préconditions

Créer deux utilisateurs avec des audits `pending`, `running`, `failed` et `completed`, puis simuler les réponses du fournisseur IA.

## Scénarios

1. Appeler les routes utilisateur sans jeton : attendre `401`.
2. Générer ou lire une recommandation pour l’audit d’un autre utilisateur : attendre `404`.
3. Essayer de générer sur un audit non terminé : attendre `409` et aucun appel fournisseur.
4. Générer sur un audit terminé : stocker et retourner la recommandation.
5. Parcourir l’historique paginé, du plus récent au plus ancien, avec `per_page` plafonné à 50.
6. Provoquer une erreur fournisseur ou un JSON invalide : attendre une erreur générique sans données brutes.
7. Vérifier que la clé API n’apparaît ni dans les réponses ni dans les journaux d’usage.
8. Dépasser la limite de génération : attendre `429`.
9. Vérifier que la vue administrative globale limite le texte à un aperçu de 300 caractères et n’expose aucune donnée fournisseur sensible.

## Résultat attendu

Seul le propriétaire d’un audit terminé peut générer et consulter ses recommandations. La pagination, les limites de débit et l’assainissement des erreurs restent actifs.

## Fichiers PHPUnit associés

- `tests/Feature/AiRecommendationApiTest.php`
- `tests/Feature/AdminRecommendationApiTest.php`

## État actuel

**Validé** — accès, état, pagination, secrets et vue administrative couverts.
