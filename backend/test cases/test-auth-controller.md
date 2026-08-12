# Cas de test — Authentification, activation et rôles

## Objectif

Vérifier que le cycle d’authentification Sanctum impose la vérification e-mail, l’activation du compte, la révocation des jetons et la séparation sûre des rôles.

## Routes concernées

`POST /api/register`, `POST /api/login`, `GET /api/me`, `POST /api/logout`, `POST /api/logout-all`, `GET /api/email/verify/{id}/{hash}`, `POST /api/email/verification-notification`, ainsi que la commande `php artisan make:admin {email}`.

## Préconditions

Disposer selon le scénario d’un utilisateur absent, non vérifié, vérifié, actif, inactif ou déjà administrateur. Les notifications et le hachage sont observés sans appeler un service externe.

## Scénarios

1. Inscrire un utilisateur et vérifier qu’il est actif, régulier, non vérifié, que son mot de passe est haché et qu’aucun jeton n’est retourné.
2. Refuser la connexion d’un utilisateur non vérifié, puis accepter la connexion après vérification avec émission d’un Bearer token.
3. Refuser la connexion d’un utilisateur inactif sans créer de jeton ; désactiver un utilisateur déjà connecté et vérifier que sa prochaine requête reçoit `403` et révoque le jeton courant.
4. Reconnecter un utilisateur vérifié et vérifier que ses anciens jetons sont supprimés.
5. Vérifier que `logout` révoque le jeton courant et que `logout-all` révoque toutes les sessions.
6. Dépasser les limites de connexion, inscription et renvoi de vérification selon l’e-mail et l’IP ; attendre `429` sans contournement par rotation d’identité.
7. Comparer un e-mail inconnu et un mauvais mot de passe : la réponse reste uniforme et un hash factice est vérifié pour limiter l’énumération.
8. Injecter `role=admin`, `is_active` ou des métadonnées de blocage à l’inscription : les valeurs ne doivent pas être appliquées.
9. Exécuter `make:admin` sur un utilisateur existant, inconnu puis déjà administrateur : promotion réussie, erreur claire sans création, puis comportement idempotent.

## Résultat attendu

Seuls les utilisateurs actifs et vérifiés obtiennent un jeton. Aucun endpoint public ne permet une promotion. Les jetons sont révoqués aux moments prévus et les réponses ne révèlent pas l’existence d’un compte.

## Fichiers PHPUnit associés

- `tests/Feature/AuthenticationTest.php`
- `tests/Feature/AdminRoleTest.php`
- `tests/Feature/UserActivationTest.php`

## État actuel

**Validé** — scénarios automatisés présents et réussis dans la suite complète.
