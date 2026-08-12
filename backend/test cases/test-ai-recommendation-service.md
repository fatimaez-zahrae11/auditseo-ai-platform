# Cas de test — Sécurité du service IA

## Objectif

Vérifier que le service IA ne contacte qu’un fournisseur explicitement autorisé et ne transmet que les signaux SEO nécessaires.

## Routes concernées

`POST /api/audits/{audit}/recommendations`.

## Préconditions

Disposer d’un audit terminé et simuler des configurations d’URL fournisseur, redirections, longueurs et réponses JSON.

## Scénarios

1. Accepter uniquement une URL HTTPS dont l’hôte correspond exactement à la liste autorisée.
2. Refuser HTTP, les hôtes inattendus, les jokers et les configurations manquantes ou mal formées.
3. Ne suivre aucune redirection du fournisseur.
4. Vérifier que le prompt contient une sélection minimale de signaux et des URL assainies.
5. Refuser une réponse trop grande avant lecture lorsque la longueur est fiable, puis pendant le streaming dans tous les autres cas.
6. Appliquer la limite après décompression et limiter aussi la longueur finale du texte généré.
7. Transformer les erreurs réseau, JSON et fournisseur en réponse générique sans clé ni réponse brute.

## Résultat attendu

Le service contacte exclusivement l’hôte HTTPS prévu, borne les données entrantes et sortantes et ne révèle aucun secret.

## Fichiers PHPUnit associés

- `tests/Feature/AiRecommendationApiTest.php`

## État actuel

**Validé** — allowlist exacte, redirections, prompt et limites de taille couverts.
