# Journalisation et confidentialité

## Objectif

Vérifier que les journaux restent utiles sans stocker les données sensibles des requêtes.

## Cas couverts

- [x] Création d’un access log pour une requête API normale
- [x] `user_id` présent pour une requête authentifiée et nul sinon
- [x] Corps, query string, cookies et Authorization non stockés
- [x] Endpoint de santé public exclu des logs
- [x] Échec de journalisation sans impact sur la réponse
- [x] Métadonnées sensibles retirées des actions admin
- [x] Échec d’admin action logging isolé
- [x] Journaux système limités et expurgés

## Fichiers PHPUnit liés

- `tests/Feature/AccessLogTest.php`
- `tests/Feature/AdminActionLogApiTest.php`
- `tests/Feature/AdminSystemApiTest.php`

## Résultat attendu

Seules les métadonnées prévues sont conservées. Une panne de journalisation ne doit pas casser l’action principale.

## État actuel

Couvert par les tests automatisés. Dernier résultat global : 343 tests réussis, 3677 assertions.
