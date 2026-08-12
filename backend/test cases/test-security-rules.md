# Cas de test — Règles de sécurité transversales

## Objectif

Fournir une matrice de contrôle des protections partagées : authentification, IDOR, limitation de débit, SSRF, secrets et réponses d’erreur.

## Routes concernées

Toutes les routes `/api`, avec un accent sur `/api/audits`, `/api/admin`, les recommandations IA et la santé.

## Préconditions

Disposer d’utilisateurs de rôles et états différents, de ressources appartenant à plusieurs comptes et de charges contenant des données sensibles factices.

## Scénarios

1. Vérifier `401` sans authentification, `403` pour les droits ou états insuffisants et `404` pour les ressources non possédées.
2. Confirmer que les routes utilisateur ne retournent jamais les audits ou recommandations d’un autre compte.
3. Confirmer que toutes les routes administratives refusent un utilisateur régulier et un administrateur inactif.
4. Dépasser les limiteurs globaux et spécifiques sans supprimer les couches existantes.
5. Envoyer des URL SSRF, réponses surdimensionnées, erreurs IA et exceptions internes : la réponse reste bornée et générique.
6. Rechercher mots de passe, clés, jetons, cookies, DSN et traces dans les réponses et journaux exposés : aucune fuite n’est acceptée.

## Résultat attendu

Les contrôles sont cohérents entre endpoints et les erreurs ne contournent ni l’autorisation, ni les limites, ni l’assainissement.

## Fichiers PHPUnit associés

- `tests/Feature/AuthenticationTest.php`
- `tests/Feature/AuditApiTest.php`
- `tests/Feature/AiRecommendationApiTest.php`
- `tests/Feature/AdminMiddlewareTest.php`
- `tests/Feature/ApiExceptionHandlingTest.php`
- `tests/Feature/GlobalRateLimitTest.php`
- `tests/Feature/AuthenticatedRateLimitingTest.php`

## État actuel

**Validé** — protections transversales réussies dans la suite complète.
