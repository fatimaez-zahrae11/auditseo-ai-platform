# Cas de test — Journalisation et confidentialité

## Objectif

Vérifier que les journaux d’accès et d’actions administratives sont utiles à la supervision sans conserver de données secrètes.

## Routes concernées

Routes API normales, `GET /api/admin/action-logs`, `GET /api/admin/system/logs` et actions sensibles sur les utilisateurs.

## Préconditions

Créer des requêtes authentifiées et anonymes contenant volontairement corps, query string, cookies et en-tête Authorization factices ; disposer d’un administrateur actif.

## Scénarios

1. Effectuer une requête normale : créer une ligne sûre avec méthode, chemin, statut, IP, user-agent et utilisateur éventuel.
2. Vérifier que corps, query string, cookies, Authorization, mots de passe, jetons et clés API ne sont pas stockés.
3. Simuler une panne d’écriture d’`access_logs` : la réponse API d’origine reste inchangée.
4. Enregistrer une action administrative avec des clés sensibles imbriquées : elles sont supprimées récursivement.
5. Simuler une panne d’`admin_action_logs` pendant une création ou désactivation : l’action métier reste réussie.
6. Lire le journal Laravel via l’administration : les lignes sont limitées, le fichier est fixe et les secrets, chemins sensibles et traces sont expurgés.

## Résultat attendu

Seules les métadonnées minimales autorisées sont conservées. Aucun échec de journalisation ne casse une réponse normale.

## Fichiers PHPUnit associés

- `tests/Feature/AccessLogTest.php`
- `tests/Feature/AdminActionLogApiTest.php`
- `tests/Feature/AdminSystemApiTest.php`

## État actuel

**Validé** — confidentialité, redaction et tolérance aux pannes couvertes.
