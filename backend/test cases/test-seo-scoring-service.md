# Calcul des scores SEO

## Objectif

Vérifier que les signaux détectés modifient les bonnes composantes du score sans dépasser les bornes.

## Cas couverts

- [x] Pages lentes ou volumineuses
- [x] Métadonnées de performance
- [x] Liens et contenu on-page
- [x] Robots, sitemap et technique
- [x] Crawl multi-page
- [x] Données structurées
- [x] Qualité globale du site
- [x] Bornage des scores

## Fichiers PHPUnit liés

- `tests/Unit/SeoScoringServiceTest.php`

## Résultat attendu

Chaque problème réduit la composante prévue et tous les scores restent dans l’intervalle autorisé.

## État actuel

Couvert par les tests automatisés. Dernier résultat global : 343 tests réussis, 3677 assertions.
