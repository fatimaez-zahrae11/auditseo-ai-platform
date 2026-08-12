# Cas de test — Calcul des scores SEO

## Objectif

Vérifier que les signaux techniques, contenu, liens et performance influencent correctement les scores sans sortir des bornes prévues.

## Routes concernées

Résultats visibles via `GET /api/audits/{audit}` et `GET /api/dashboard` après traitement asynchrone.

## Préconditions

Fournir au service de scoring des jeux de données contrôlés représentant des pages saines et des problèmes SEO variés.

## Scénarios

1. Dégrader le score de performance pour une page lente ou volumineuse.
2. Dégrader et borner les scores selon les métadonnées HTTP, liens, contenu on-page, robots et sitemap.
3. Appliquer les pénalités de crawl multi-page, données structurées et qualité globale du site.
4. Vérifier que les scores restent dans les limites attendues.

## Résultat attendu

Chaque famille de signaux affecte la composante appropriée et aucun score ne dépasse ses bornes.

## Fichiers PHPUnit associés

- `tests/Unit/SeoScoringServiceTest.php`

## État actuel

**Validé** — huit scénarios unitaires couvrent les familles de score.
