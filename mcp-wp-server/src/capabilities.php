<?php

/*
 * La carte du serveur : tout ce qu'il expose, déclaré dans un seul tableau.
 * Un tool = description + schéma JSON + annotations + closure d'exécution.
 * Les parties descriptives partent telles quelles dans la réponse de
 * discovery (tools/list) ; les closures sont exécutées par tools/call.
 *
 * Les handlers rendent des DONNÉES : c'est Server::callTool() qui les
 * sérialise, parce que le format de sortie est une affaire de protocole.
 *
 * Choix assumé : uniquement des tools. Le langage naturel les déclenche tout
 * seul, là où resources et prompts demandent un geste explicite (@, /) ; la
 * majorité des serveurs MCP en production font pareil. Le serveur répond
 * quand même poliment (listes vides) aux méthodes resources/* et prompts/*.
 *
 * Ce fichier est inclus par public/index.php, qui fournit $dao.
 */

use Mcp\Wp\PostDao;

/** @var PostDao $dao */

return [

    // ------------------------------------------------------------- tools --

    'tools' => [
        'search_posts' => [
            'description' => "Cherche et liste les articles du blog, du plus récent au plus ancien. "
                . "Sans paramètre : les derniers articles. Avec status=draft : les brouillons. "
                . "Avec query : recherche dans les titres et les contenus.",
            'inputSchema' => ['type' => 'object', 'properties' => [
                'query'  => ['type' => 'string', 'description' => "Texte recherché (optionnel)"],
                'status' => ['type' => 'string', 'enum' => ['publish', 'draft', 'any'],
                             'description' => "Filtrer par statut (défaut : any)"],
                'limit'  => ['type' => 'integer', 'description' => "Nombre maximum de résultats (défaut : 20)"],
            ]],
            'annotations' => ['readOnlyHint' => true],
            'handler' => function (array $args) use ($dao): array {
                return $dao->searchPosts($args['query'] ?? null, $args['status'] ?? 'any', $args['limit'] ?? 20);
            },
        ],

        'get_post' => [
            'description' => "Retourne un article complet : contenu, statut, dates, catégories et tags.",
            'inputSchema' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer', 'description' => "Identifiant de l'article"],
            ], 'required' => ['id']],
            'annotations' => ['readOnlyHint' => true],
            'handler' => function (array $args) use ($dao): array {
                if (!isset($args['id']))
                    throw new \InvalidArgumentException("Paramètre id requis.");
                return $dao->getPost($args['id']);
            },
        ],

        'update_post' => [
            'description' => "Remplace le contenu d'un article (le titre et le slug ne changent pas). "
                . "Peut aussi changer son statut (draft ou publish).",
            'inputSchema' => ['type' => 'object', 'properties' => [
                'id'      => ['type' => 'integer', 'description' => "Identifiant de l'article"],
                'content' => ['type' => 'string', 'description' => "Nouveau contenu complet (HTML classic editor)"],
                'status'  => ['type' => 'string', 'enum' => ['draft', 'publish'], 'description' => "Nouveau statut (optionnel)"],
            ], 'required' => ['id', 'content']],
            // écrase du contenu (destructif), mais rejouable sans dégât (idempotent)
            'annotations' => ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true],
            'handler' => function (array $args) use ($dao): array {
                if (!isset($args['id'], $args['content']))
                    throw new \InvalidArgumentException("Paramètres id et content requis.");
                $status = $args['status'] ?? null;
                if ($status !== null && !in_array($status, ['draft', 'publish'], true))
                    throw new \InvalidArgumentException("Statut invalide : draft ou publish.");
                return $dao->updatePost($args['id'], $args['content'], $status);
            },
        ],
    ],
];

