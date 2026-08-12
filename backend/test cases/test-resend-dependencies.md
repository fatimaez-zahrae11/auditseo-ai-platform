# Cas de test — Resend et sécurité des dépendances

## Objectif

Vérifier que l’envoi sortant d’e-mails reste disponible sans exposer le webhook entrant inutilisé, et que les dépendances connues vulnérables sont absentes.

## Routes concernées

`POST /api/register`, `POST /api/email/verification-notification` et l’ancienne route `POST /resend/webhook`.

## Préconditions

Charger l’application avec le provider Resend applicatif, utiliser une clé factice uniquement pour résoudre le transport et disposer du lock Composer courant.

## Scénarios

1. Résoudre le mailer `resend` et confirmer que son transport sortant est `ResendTransportFactory`.
2. Vérifier que le nom de route `resend.webhook` n’existe pas.
3. Envoyer `POST /resend/webhook` avec un événement factice : attendre `404` et aucun traitement.
4. Exécuter `composer audit` : attendre zéro avis.
5. Vérifier dans `composer.lock` et via Composer que `league/commonmark` est en version `2.10.0`, et non `2.8.2`.

## Résultat attendu

Les notifications sortantes conservent leur transport, aucun webhook entrant non signé n’est accessible et le verrou de dépendances est exempt des avis corrigés.

## Fichiers PHPUnit associés

- `tests/Feature/ResendWebhookTest.php`
- `tests/Feature/AuthenticationTest.php`

## État actuel

**Validé** — webhook absent et transport sortant disponible ; `composer audit` ne signale aucun avis.
