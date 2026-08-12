# Cas de test — Sécurité SSRF et crawler SEO

## Objectif

Prouver que le crawler ne peut pas être utilisé pour atteindre des services internes et qu’il borne toutes les réponses distantes.

## Routes concernées

Principalement `POST /api/audits`, puis le job asynchrone associé.

## Préconditions

Utiliser des résolutions DNS et réponses HTTP simulées : adresses publiques, locales, privées, spéciales, redirections et contenus de tailles variées.

## Scénarios

1. Refuser `localhost`, IPv4/IPv6 loopback, privées, link-local, partagées et réseaux spéciaux.
2. Refuser les URL contenant un nom d’utilisateur ou mot de passe et les schémas comme `file:` ou `ftp:`.
3. Exiger l’épinglage DNS ; si le transport sécurisé n’est pas disponible, échouer de façon fermée.
4. Réévaluer chaque redirection de page, ressource secondaire ou lien et bloquer toute destination non publique.
5. Interrompre un HTML trop volumineux, y compris lorsque `Content-Length` est absent ou trompeur.
6. Appliquer les limites après décompression afin qu’une petite réponse compressée ne puisse pas gonfler sans borne.
7. Ignorer ou limiter sans danger les fichiers `robots.txt`, sitemaps et sitemaps enfants trop grands.
8. Borner les vérifications de liens et interrompre les corps dépassant la limite.

## Résultat attendu

Aucune requête n’atteint une adresse interdite. Chaque saut est revalidé, les tailles sont bornées pendant le streaming et les échecs produisent des messages sûrs.

## Fichiers PHPUnit associés

- `tests/Feature/StoreAuditRequestTest.php`
- `tests/Feature/AuditApiTest.php`

## État actuel

**Validé** — scénarios SSRF, DNS, redirections et tailles compressées réussis.
