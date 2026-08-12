# Resend et dépendances

## Objectif

Vérifier que l’envoi sortant reste disponible et que le webhook entrant inutilisé est absent.

## Cas couverts

- [x] Transport sortant Resend toujours enregistré
- [x] Route nommée `resend.webhook` absente
- [x] `POST /resend/webhook` retourne `404`
- [x] `composer audit` sans avis
- [x] `league/commonmark` fixé en version `2.10.0`

## Fichiers PHPUnit liés

- `tests/Feature/ResendWebhookTest.php`
- `tests/Feature/AuthenticationTest.php`

## Résultat attendu

Les e-mails de vérification gardent le transport Resend, sans exposer de webhook entrant non utilisé.

## État actuel

Couvert par les tests automatisés. Dernier résultat global : 343 tests réussis, 3677 assertions.
