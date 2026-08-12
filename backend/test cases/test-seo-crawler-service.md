# Sécurité SSRF et crawler SEO

## Objectif

Vérifier que le crawler atteint seulement des destinations publiques validées et respecte les limites de taille.

## Cas couverts

- [x] Blocage de localhost, des IP privées et des réseaux spéciaux IPv4/IPv6
- [x] Refus des identifiants dans l’URL et des schémas non sûrs
- [x] Résolution et épinglage DNS obligatoires
- [x] Revalidation de chaque redirection
- [x] Limites sur HTML normal, chunked et compressé
- [x] Traitement borné de `robots.txt` et des sitemaps
- [x] Vérification bornée des liens et ressources secondaires
- [x] Échec fermé si l’épinglage DNS n’est pas disponible

## Fichiers PHPUnit liés

- `tests/Feature/StoreAuditRequestTest.php`
- `tests/Feature/AuditApiTest.php`

## Résultat attendu

Aucune requête ne doit atteindre une adresse interdite. Les réponses trop grandes sont interrompues sans fuite technique.

## État actuel

Couvert par les tests automatisés. Dernier résultat global : 343 tests réussis, 3677 assertions.
