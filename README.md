# mcp-wp-server

Serveur MCP (Model Context Protocol) en PHP pur, branché sur une base
WordPress via PDO. Support de la conférence « Sous le capot du protocole
MCP : codons un serveur en PHP pur » (Forum PHP 2026).

- Protocole : MCP révision 2026-07-28 (stateless), transport Streamable HTTP
  (POST /mcp). Pas de handshake, pas de session : chaque requête est un POST
  autoportant, exactement le modèle d'exécution de PHP
- PHP 8.3 minimum, aucune dépendance Composer (PDO, json_*, headers HTTP)
- Le cœur du protocole (transport, JSON-RPC, dispatch) tient en moins de
  300 lignes utiles ; l'intégration WordPress se déclare dans un tableau
  de capacités (src/capabilities.php) qui s'appuie sur un DAO PDO

## Structure

Cinq fichiers :

    public/index.php        point d'entrée : autoloader, config, PDO
    src/Server.php          transport HTTP + dispatch des méthodes MCP
    src/JsonRpc.php         encodage/décodage JSON-RPC 2.0
    src/capabilities.php    la carte du serveur : 3 tools (search_posts,
                            get_post, update_post) déclarés dans un tableau
                            associatif (descriptions pour la discovery,
                            annotations, closures d'exécution)
    src/Wp/PostDao.php      requêtes PDO sur la base WordPress

Plus le matériel de la conférence :

    tests/smoke.php         client de validation JSON-RPC
    fixtures/wordpress-demo.sql  contenu de démo (blog de cuisine)
    snippets/               extraits de code alignés sur les slides

Dans un vrai projet, on remplacerait le tableau de capabilities.php par des
classes (une par primitive, derrière des interfaces) : ici, le tableau garde
tout le serveur lisible d'un seul tenant.

## Installation

### 1. WordPress de démo

Un WordPress complet et déjà configuré est fourni dans le dossier `wpdemo/`
à la racine du dépôt (core WP 7.0, thème twentytwentyfive, admin en
français, aucun plugin, `wp-config.php` prérempli : base `wpdemo`,
utilisateur `wpdemo`/`wpdemo`, URL fixe http://127.0.0.1:8081).

Mise en route clé en main, dans une base `wpdemo` vide :

    mysql -u wpdemo -p wpdemo < mcp-wp-server/fixtures/wordpress-demo-complete.sql
    cd wpdemo && php -S 127.0.0.1:8081

- Site :  http://127.0.0.1:8081 (le pied de page contient un lien « Connexion
  à l'administration », pratique pour basculer pendant la démo)
- Admin : http://127.0.0.1:8081/wp-admin/ (identifiants : wpdemo / wpdemo)

Le pied de page est un template part du thème, simplifié pour la démo :
`wpdemo/wp-content/themes/twentytwentyfive/parts/footer.html` (les menus
et le lien WordPress.org du thème d'origine ont été retirés).

Le contenu : 2 recettes publiées (risotto, mayonnaise) et 1 brouillon
(pâte à choux, interrompu en pleine phrase), courts et dans un ton simple,
avec une structure reconnaissable : Ingrédients, Préparation, Mon conseil.
C'est le carburant des démos en langage naturel : terminer le brouillon
dans le style de l'auteur, ou réécrire une recette façon Yoda.

Alternative si tu préfères ta propre installation WordPress : installe-la
normalement, puis importe seulement le contenu (dump commenté et lisible) :

    mysql -u root wordpress < mcp-wp-server/fixtures/wordpress-demo.sql

Dans les deux cas, pour la démo : aucun plugin de cache (ni page cache, ni
object cache, vérifier l'absence de `wp-content/object-cache.php` et
`advanced-cache.php`), et éditeur classique conseillé (le contenu est au
format classic editor, sans blocs Gutenberg).

### 2. Utilisateur SQL à droits limités

Le serveur MCP n'a besoin que de lire, et d'écrire dans `wp_posts` :

    CREATE USER 'mcp'@'127.0.0.1' IDENTIFIED BY 'motdepasse';
    GRANT SELECT ON wpdemo.* TO 'mcp'@'127.0.0.1';
    GRANT UPDATE (post_content, post_modified, post_modified_gmt, post_status)
        ON wpdemo.wp_posts TO 'mcp'@'127.0.0.1';

Le GRANT UPDATE par colonne garantit au niveau SQL que ni le titre, ni le
slug, ni les dates de création ne peuvent être modifiés, quoi que fasse
le LLM. Défense en profondeur.

### 3. Serveur MCP

    cp config.php.dist config.php   # puis renseigner les credentials
    php -S 127.0.0.1:8080 public/index.php

Le serveur répond sur `POST http://127.0.0.1:8080/mcp`. Test rapide (les
en-têtes miroirs MCP-Protocol-Version et Mcp-Method sont requis par la
révision 2026-07-28, et vérifiés contre le corps) :

    curl -s http://127.0.0.1:8080/mcp \
      -H 'Content-Type: application/json' \
      -H 'MCP-Protocol-Version: 2026-07-28' \
      -H 'Mcp-Method: tools/list' \
      -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'

### 4. Claude Code

Claude Code (CLI) gère nativement le transport Streamable HTTP et la
révision 2026-07-28 (négociation automatique). En une commande :

    claude mcp add --transport http blog http://127.0.0.1:8080/mcp

(Ajouter `--header "Authorization: Bearer <token>"` si `auth_token` est
renseigné, et `--scope project` pour partager la config via un fichier
`.mcp.json` versionné.)

Vérification :

    claude mcp list

Dans la session, tout est ensuite accessible :

Les 3 tools (`search_posts`, `get_post`, `update_post`) sont ensuite
invoqués automatiquement par le LLM : on parle en langage naturel
(« Termine mon brouillon sur la pâte à choux »), rien d'autre à faire.

## Validation

Serveur lancé et dump importé :

    php tests/smoke.php

Le script simule le trafic d'un client MCP (server/discover, tools/list,
tools/call sur chaque tool, resources, prompts, cas d'erreur) et vérifie
notamment qu'après `update_post` la nouvelle valeur est bien en base avec
`post_modified` rafraîchi. Voir VALIDATION.md pour le dernier run.

## Sécurité (démo locale uniquement)

- `auth_token` vide = aucune authentification : ne convient qu'en local.
  Renseigner un jeton pour exiger `Authorization: Bearer <token>`.
- L'en-tête Origin, quand il est présent, doit désigner 127.0.0.1 ou
  localhost (protection contre le DNS rebinding).
- Les en-têtes miroirs sont validés contre le corps (400 + code -32020 en
  cas d'écart), et la version de protocole est vérifiée à chaque requête
  (400 + code -32022 avec la liste des versions supportées).

## Périmètre (choix assumés, conformes à la révision 2026-07-28)

- Uniquement des tools : pas de resources ni de prompts exposés (les
  méthodes resources/* et prompts/* répondent des listes vides). Le langage
  naturel déclenche les tools tout seul, là où resources et prompts
  demandent un geste explicite de l'utilisateur ; la majorité des serveurs
  MCP en production font le même choix. Pour changer d'avis : ajouter une
  entrée 'resources' ou 'prompts' dans capabilities.php.
- Réponses en JSON simple : pas de flux SSE par requête (optionnel dans la
  spec), donc pas de notifications de progression.
- `subscriptions/listen` (notifications de changement) non implémenté.
- Multi Round-Trip Requests (elicitation, sampling) non implémentés : les
  tools de ce serveur n'ont jamais besoin de redemander une entrée.
- Encodage Base64 sentinel des en-têtes `Mcp-Name` non ASCII non géré
  (tous les noms de ce serveur sont ASCII).
