# Authentification, activation et rôles

## Objectif

Vérifier l’inscription, la vérification e-mail, les jetons Sanctum et les règles de rôle et d’activation.

## Cas couverts

- [x] Inscription sans jeton, compte régulier actif et non vérifié
- [x] Connexion autorisée seulement si le compte est vérifié et actif
- [x] Refus uniforme des identifiants invalides
- [x] Révocation des anciens jetons à la connexion
- [x] `logout`, `logout-all` et expiration des jetons
- [x] Blocage d’un ancien jeton après désactivation
- [x] Limites de débit sur inscription, connexion et renvoi d’e-mail
- [x] Refus de l’injection de rôle ou de champs de blocage
- [x] Commande `make:admin` : promotion, utilisateur inconnu et idempotence

## Fichiers PHPUnit liés

- `tests/Feature/AuthenticationTest.php`
- `tests/Feature/AdminRoleTest.php`
- `tests/Feature/UserActivationTest.php`

## Résultat attendu

Seul un compte vérifié et actif obtient un jeton. Aucune route publique ne permet de devenir administrateur.

## État actuel

Couvert par les tests automatisés. Dernier résultat global : 343 tests réussis, 3677 assertions.
