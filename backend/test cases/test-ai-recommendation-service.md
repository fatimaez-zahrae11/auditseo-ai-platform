# Service de recommandations IA

## Objectif

Contrôler la destination du fournisseur, le contenu envoyé et les limites appliquées aux réponses.

## Cas couverts

- [x] HTTPS obligatoire et correspondance exacte avec l’allowlist
- [x] Refus des hôtes inattendus et configurations invalides
- [x] Redirections désactivées
- [x] Prompt limité aux signaux SEO utiles et URL assainies
- [x] Limites avant et pendant la lecture de la réponse
- [x] Limites après décompression et sur le texte final
- [x] Erreurs réseau, JSON et fournisseur transformées en erreur générique

## Fichiers PHPUnit liés

- `tests/Feature/AiRecommendationApiTest.php`

## Résultat attendu

Le service contacte uniquement l’hôte HTTPS prévu et ne transmet ni ne retourne de donnée sensible inutile.

## État actuel

Couvert par les tests automatisés. Dernier résultat global : 343 tests réussis, 3677 assertions.
