# API de recommandations IA

## Objectif

Vérifier l’accès aux recommandations, la condition d’état de l’audit et la pagination de l’historique.

## Cas couverts

- [x] Accès refusé sans authentification
- [x] Génération et lecture réservées au propriétaire de l’audit
- [x] Génération autorisée seulement pour un audit `completed`
- [x] Historique trié et paginé, `per_page` plafonné à 50
- [x] Erreurs fournisseur et JSON invalide assainis
- [x] Clé API absente des réponses et journaux
- [x] Limite de débit sur la génération
- [x] Vue admin globale avec aperçu limité à 300 caractères

## Fichiers PHPUnit liés

- `tests/Feature/AiRecommendationApiTest.php`
- `tests/Feature/AdminRecommendationApiTest.php`

## Résultat attendu

Un utilisateur travaille uniquement sur ses audits terminés. Les erreurs et secrets du fournisseur ne sont pas exposés.

## État actuel

Couvert par les tests automatisés. Dernier résultat global : 343 tests réussis, 3677 assertions.
