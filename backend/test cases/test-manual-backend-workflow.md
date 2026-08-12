# Cas de test — Parcours manuel du backend

## Objectif

Compléter les tests automatisés par un contrôle de bout en bout dans un environnement local ou de staging, sans remplacer PHPUnit.

## Routes concernées

Inscription, vérification, connexion, audits, recommandations, dashboard, santé et routes administratives.

## Préconditions

PostgreSQL et Redis configurés, migrations appliquées, worker de file actif, compte e-mail de test et administrateur créé avec `make:admin`. Ne jamais utiliser de secret de production dans une collection partagée.

## Scénario

1. Inscrire un compte, suivre le lien signé de vérification puis se connecter.
2. Soumettre un audit et interroger son URL de polling jusqu’à un état terminal.
3. Consulter liste, détail et dashboard, puis générer une recommandation uniquement après réussite.
4. Vérifier avec un second compte que les ressources du premier restent invisibles.
5. Vérifier avec un compte administrateur les 13 routes globales, la pagination, les filtres et les actions de blocage.
6. Désactiver un utilisateur connecté et confirmer le refus du jeton existant.
7. Vérifier que `POST /resend/webhook` retourne `404`.

## Résultat attendu

Le parcours reproduit les contrats documentés, le worker traite l’audit et aucune donnée d’un autre utilisateur ou secret n’est exposée.

## Fichiers PHPUnit associés

- `tests/Feature/AuthenticationTest.php`
- `tests/Feature/AuditApiTest.php`
- `tests/Feature/AiRecommendationApiTest.php`
- `tests/Feature/AdminUserApiTest.php`
- `tests/Feature/ResendWebhookTest.php`

## État actuel

**À exécuter par environnement** — les équivalents automatisés sont validés ; les dépendances réelles doivent être testées après déploiement.
