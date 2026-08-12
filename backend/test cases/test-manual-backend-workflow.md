# Parcours manuel du backend

## Objectif

Contrôler le parcours réel après déploiement, en complément des tests automatisés.

## Cas couverts

- [ ] Inscrire, vérifier puis connecter un utilisateur
- [ ] Créer un audit et suivre son état jusqu’au résultat final
- [ ] Consulter liste, détail, dashboard et recommandation IA
- [ ] Vérifier l’isolation avec un second compte
- [ ] Parcourir les 13 routes admin avec un administrateur actif
- [ ] Désactiver un utilisateur connecté et retester son ancien jeton
- [ ] Vérifier que `POST /resend/webhook` retourne `404`
- [ ] Envoyer un e-mail de vérification avec le transport Resend réel

## Fichiers PHPUnit liés

- `tests/Feature/AuthenticationTest.php`
- `tests/Feature/AuditApiTest.php`
- `tests/Feature/AiRecommendationApiTest.php`
- `tests/Feature/AdminUserApiTest.php`
- `tests/Feature/ResendWebhookTest.php`

## Résultat attendu

Le comportement observé correspond à l’API documentée. Les dépendances réelles sont testées sans utiliser de secrets dans les captures ou collections partagées.

## État actuel

À exécuter sur l’environnement cible. Couverture automatisée actuelle : 343 tests réussis, 3677 assertions.
