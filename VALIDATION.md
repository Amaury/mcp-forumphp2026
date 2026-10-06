# VALIDATION.md

Validation par exécution du serveur MCP, conformément au cahier des charges.
Cible : révision de protocole MCP 2026-07-28 (stateless), publiée le 28
juillet 2026.

## Environnement

- PHP 8.3.6 (CLI, serveur `php -S 127.0.0.1:8080 public/index.php`)
- MariaDB 10.6.23
- Base `wpdemo` : créée par le véritable installeur WordPress 7.0 (celui du
  dossier `wpdemo/` du dépôt, admin wpdemo, interface française, préfixe de
  tables `wp_` codé en dur dans le DAO), puis
  import de `fixtures/wordpress-demo.sql`. Le front et l'admin ont été
  vérifiés par requêtes HTTP : accueil listant les recettes, connexion de
  l'utilisateur wpdemo, tableau de bord et liste des articles accessibles.
- Utilisateur SQL `wpdemo` à droits limités : `GRANT SELECT` global +
  `GRANT UPDATE (post_content, post_modified, post_modified_gmt, post_status)`
  sur `wp_posts` uniquement (le GRANT par colonne décrit dans le README)

## Protocole de test

`tests/smoke.php` simule un client MCP moderne (2026-07-28) : requêtes
autoportantes avec en-têtes miroirs (MCP-Protocol-Version, Mcp-Method,
Mcp-Name) et `_meta` (protocolVersion, clientInfo, clientCapabilities).
Sont vérifiés : server/discover, chaque tool (search_posts sous ses trois
usages : brouillons, articles récents, recherche plein texte ; get_post ;
update_post), les annotations, les réponses vides et polies des méthodes
resources/* et prompts/* (aucune resource ni prompt exposé, par choix),
la décoration des résultats
(resultType, serverInfo, ttlMs/cacheScope), et les cas d'erreur propres à
la révision : -32020 (HeaderMismatch, HTTP 400), -32022
(UnsupportedProtocolVersion avec data.supported, HTTP 400), -32601
(méthode inconnue, HTTP 404), -32602 (resource introuvable), rejet d'un
`initialize` legacy avec un message nommant les versions supportées.
La vérification d'update_post se fait en relisant directement la base
(contenu, post_modified rafraîchi, titre et slug intacts), puis le
brouillon est remis dans son état d'origine.

## Résultat du run

```
=== Validation MCP 2026-07-28 (stateless) sur http://127.0.0.1:8080/mcp ===

[OK  ] server/discover : HTTP 200
[OK  ] server/discover : supportedVersions contient 2026-07-28
[OK  ] server/discover : capabilities annonce tools (et rien d'autre)
[OK  ] server/discover : resultType complete
[OK  ] server/discover : serverInfo dans _meta
[OK  ] server/discover : ttlMs + cacheScope
[OK  ] tools/list : 3 tools (search_posts, get_post, update_post)
[OK  ] tools/list : search_posts présent
[OK  ] tools/list : get_post présent
[OK  ] tools/list : update_post présent
[OK  ] tools/list : resultType + ttlMs + cacheScope (CacheableResult)
[OK  ] tools/list : search_posts a un inputSchema de type object
[OK  ] tools/list : get_post a un inputSchema de type object
[OK  ] tools/list : update_post a un inputSchema de type object
[OK  ] tools/list : annotations readOnlyHint sur search_posts et get_post
[OK  ] tools/list : update_post annoncé destructif et idempotent
[OK  ] search_posts(status=draft) : 1 brouillon (Pâte à choux)
[OK  ] search_posts(status=publish) : 2 articles publiés
[OK  ] search_posts(limit=1) : la limite est respectée
[OK  ] search_posts() : les 3 articles (publiés + brouillon)
[OK  ] search_posts(query=carnaroli) : trouve le risotto (101)
[OK  ] get_post(101) : titre, contenu, taxonomies
[OK  ] update_post : réponse updated=true
[OK  ] update_post : nouveau contenu présent en base
[OK  ] update_post : post_modified rafraîchi
[OK  ] update_post : titre intact
[OK  ] update_post : slug intact
[OK  ] get_post(99999) : isError=true dans le résultat
[OK  ] tools/call tool inconnu : erreur -32602
[OK  ] resources/list : liste vide + cache
[OK  ] resources/templates/list : liste vide
[OK  ] resources/read : erreur -32602 (aucune resource exposée)
[OK  ] prompts/list : liste vide + resultType complete
[OK  ] prompts/get : erreur -32602 (aucun prompt exposé)
[OK  ] méthode inconnue : HTTP 404 + erreur -32601
[OK  ] initialize legacy : rejeté en nommant les versions supportées
[OK  ] MCP-Protocol-Version manquant : HTTP 400 + -32020
[OK  ] Mcp-Method différent du corps : HTTP 400 + -32020
[OK  ] Mcp-Name différent du corps : HTTP 400 + -32020
[OK  ] version non supportée : HTTP 400 + -32022 + data.supported
[OK  ] JSON invalide : HTTP 400 + erreur -32700
[OK  ] message sans jsonrpc 2.0 : HTTP 400 + erreur -32600

Validation complète : tous les tests passent.
```

## Notes

- 42 vérifications, 0 échec.
- Historique : développé et validé contre 2025-06-18, migré vers 2026-07-28
  (suppression du handshake, server/discover, en-têtes miroirs, resultType,
  ttlMs/cacheScope), puis simplifié deux fois : capacités déclarées dans un
  tableau associatif avec closures (src/capabilities.php) au lieu d'une
  registry et d'une classe par primitive, et périmètre resserré à 3 tools
  seulement (search_posts unifié avec paramètres query/status/limit,
  get_post, update_post) : les resources et les prompts ne sont pas
  exposés, par choix assumé documenté dans le README. Chaque étape a été
  revalidée par ce smoke test.
- Décompte des lignes utiles (hors commentaires et lignes vides), sur
  5 fichiers : cœur du protocole 212 (public/index.php, Server.php,
  JsonRpc.php), carte des capacités 51 (capabilities.php), accès
  WordPress 60 (PostDao.php). Total 323.
