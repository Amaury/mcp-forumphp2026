<?php

/*
 * Client de validation : simule les requêtes qu'enverrait un client MCP
 * moderne (révision 2026-07-28, stateless) et vérifie la conformité des
 * réponses : en-têtes miroirs, _meta, resultType, champs de cache, codes
 * d'erreur, et effets réels en base après update_post.
 *
 * Prérequis :
 *   - le serveur tourne :  php -S 127.0.0.1:8080 public/index.php
 *   - la base contient le contenu de fixtures/wordpress-demo.sql
 *
 * Usage : php tests/smoke.php [url]
 */

const PROTO = '2026-07-28';
const META_VERSION = 'io.modelcontextprotocol/protocolVersion';
const META_CLIENT  = 'io.modelcontextprotocol/clientInfo';
const META_CAPS    = 'io.modelcontextprotocol/clientCapabilities';
const META_SERVER  = 'io.modelcontextprotocol/serverInfo';

$url = $argv[1] ?? 'http://127.0.0.1:8080/mcp';
$config = require __DIR__ . '/../config.php';

$requestId = 0;
$failures = 0;

/** Envoie un POST brut ; retourne [statut HTTP, corps texte]. */
function post(string $url, string $body, array $headers = []): array {
    $headerLines = "Content-Type: application/json\r\nAccept: application/json, text/event-stream\r\n";
    foreach ($headers as $name => $value)
        $headerLines .= "$name: $value\r\n";
    $context = stream_context_create(['http' => [
        'method' => 'POST', 'header' => $headerLines, 'content' => $body, 'ignore_errors' => true,
    ]]);
    $response = file_get_contents($url, false, $context);
    preg_match('#HTTP/\S+ (\d+)#', $http_response_header[0] ?? '', $m);
    return [(int) ($m[1] ?? 0), $response === false ? '' : $response];
}

/**
 * Envoie une requête MCP moderne : en-têtes miroirs + _meta autoportant.
 * $tamper permet d'altérer les en-têtes pour les tests négatifs.
 * Retourne [statut HTTP, réponse décodée].
 */
function rpc(string $url, string $method, array $params = [], array $tamper = []): array {
    global $requestId;
    $params['_meta'] = [
        META_VERSION => $tamper['metaVersion'] ?? PROTO,
        META_CLIENT  => ['name' => 'smoke-test', 'version' => '1.0'],
        META_CAPS    => new stdClass(),
    ];
    $msg = ['jsonrpc' => '2.0', 'id' => ++$requestId, 'method' => $method, 'params' => $params];
    $headers = [
        'MCP-Protocol-Version' => $tamper['version'] ?? PROTO,
        'Mcp-Method'           => $tamper['method'] ?? $method,
    ];
    $name = $params['name'] ?? $params['uri'] ?? null;
    if ($name !== null)
        $headers['Mcp-Name'] = $tamper['name'] ?? $name;
    foreach ($tamper['drop'] ?? [] as $h)
        unset($headers[$h]);
    [$status, $body] = post($url, json_encode($msg), $headers);
    return [$status, $body === '' ? null : json_decode($body, true)];
}

/** Appelle un tool ; retourne [isError, données décodées du texte résultat]. */
function toolCall(string $url, string $name, array $args): array {
    [, $r] = rpc($url, 'tools/call', ['name' => $name, 'arguments' => $args]);
    $text = $r['result']['content'][0]['text'] ?? '';
    return [$r['result']['isError'] ?? null, json_decode($text, true)];
}

function check(string $label, bool $ok, string $details = ''): void {
    global $failures;
    if (!$ok)
        $failures++;
    printf("[%s] %s%s\n", $ok ? 'OK  ' : 'FAIL', $label, $details !== '' ? " ($details)" : '');
}

echo "=== Validation MCP 2026-07-28 (stateless) sur $url ===\n\n";

// --- 1. Discovery et forme générale des résultats ----------------------------

[$status, $r] = rpc($url, 'server/discover');
check('server/discover : HTTP 200', $status === 200);
check('server/discover : supportedVersions contient 2026-07-28',
    in_array(PROTO, $r['result']['supportedVersions'] ?? [], true));
check('server/discover : capabilities annonce tools (et rien d\'autre)',
    isset($r['result']['capabilities']['tools']) && !isset($r['result']['capabilities']['resources'])
    && !isset($r['result']['capabilities']['prompts']));
check('server/discover : resultType complete', ($r['result']['resultType'] ?? '') === 'complete');
check('server/discover : serverInfo dans _meta', ($r['result']['_meta'][META_SERVER]['name'] ?? '') !== '');
check('server/discover : ttlMs + cacheScope', isset($r['result']['ttlMs']) && isset($r['result']['cacheScope']));

// --- 2. Tools -----------------------------------------------------------------

[, $r] = rpc($url, 'tools/list');
$tools = array_column($r['result']['tools'] ?? [], 'name');
check('tools/list : 3 tools', count($tools) === 3, implode(', ', $tools));
foreach (['search_posts', 'get_post', 'update_post'] as $name)
    check("tools/list : $name présent", in_array($name, $tools, true));
check('tools/list : resultType + ttlMs + cacheScope (CacheableResult)',
    ($r['result']['resultType'] ?? '') === 'complete' && isset($r['result']['ttlMs'], $r['result']['cacheScope']));
foreach ($r['result']['tools'] ?? [] as $tool)
    check("tools/list : {$tool['name']} a un inputSchema de type object", ($tool['inputSchema']['type'] ?? '') === 'object');
$byName = array_column($r['result']['tools'] ?? [], null, 'name');
check('tools/list : annotations readOnlyHint sur search_posts et get_post',
    ($byName['search_posts']['annotations']['readOnlyHint'] ?? false) === true
    && ($byName['get_post']['annotations']['readOnlyHint'] ?? false) === true);
check('tools/list : update_post annoncé destructif et idempotent',
    ($byName['update_post']['annotations']['destructiveHint'] ?? false) === true
    && ($byName['update_post']['annotations']['idempotentHint'] ?? false) === true);

[$err, $drafts] = toolCall($url, 'search_posts', ['status' => 'draft']);
check('search_posts(status=draft) : 1 brouillon (Pâte à choux)', $err === false && is_array($drafts)
    && count($drafts) === 1 && ($drafts[0]['post_status'] ?? '') === 'draft');
$draftId = (int) ($drafts[0]['ID'] ?? 0);

[$err, $posts] = toolCall($url, 'search_posts', ['status' => 'publish']);
check('search_posts(status=publish) : 2 articles publiés', $err === false && count($posts) === 2
    && array_diff(array_column($posts, 'post_status'), ['publish']) === []);

[$err, $one] = toolCall($url, 'search_posts', ['status' => 'publish', 'limit' => 1]);
check('search_posts(limit=1) : la limite est respectée', $err === false && count($one) === 1);

[$err, $all] = toolCall($url, 'search_posts', []);
check('search_posts() : les 3 articles (publiés + brouillon)', $err === false && count($all) === 3);

[$err, $found] = toolCall($url, 'search_posts', ['query' => 'carnaroli']);
check('search_posts(query=carnaroli) : trouve le risotto (101)', $err === false
    && in_array(101, array_map('intval', array_column($found ?? [], 'ID')), true));

[$err, $post] = toolCall($url, 'get_post', ['id' => 101]);
check('get_post(101) : titre, contenu, taxonomies', $err === false
    && str_contains($post['post_title'] ?? '', 'Risotto')
    && str_contains($post['post_content'] ?? '', 'carnaroli')
    && ($post['categories'] ?? []) === ['Recettes']
    && in_array('technique', $post['tags'] ?? [], true));

// update_post : modification, puis vérification directement en base
$pdo = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['password'], [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$table = 'wp_posts';
$stmt = $pdo->prepare("SELECT post_content, post_title, post_name, post_modified FROM $table WHERE ID = ?");
$stmt->execute([$draftId]);
$before = $stmt->fetch();

sleep(1); // pour garantir un post_modified strictement postérieur
$marker = 'Validation MCP ' . date('c');
[$err, $updated] = toolCall($url, 'update_post', ['id' => $draftId, 'content' => $before['post_content'] . "\n\n" . $marker]);
check('update_post : réponse updated=true', $err === false && ($updated['updated'] ?? false) === true);

$stmt->execute([$draftId]);
$after = $stmt->fetch();
check('update_post : nouveau contenu présent en base', str_contains($after['post_content'], $marker));
check('update_post : post_modified rafraîchi', $after['post_modified'] > $before['post_modified']);
check('update_post : titre intact', $after['post_title'] === $before['post_title']);
check('update_post : slug intact', $after['post_name'] === $before['post_name']);

// remise en état du brouillon pour laisser la base propre
$pdo->prepare("UPDATE $table SET post_content = ? WHERE ID = ?")->execute([$before['post_content'], $draftId]);

[$err, ] = toolCall($url, 'get_post', ['id' => 99999]);
check('get_post(99999) : isError=true dans le résultat', $err === true);

[$status, $r] = rpc($url, 'tools/call', ['name' => 'tool_bidon', 'arguments' => []]);
check('tools/call tool inconnu : erreur -32602', $status === 200 && ($r['error']['code'] ?? 0) === -32602);

// --- 3. Resources et prompts : non exposés, mais le protocole répond -----------

[, $r] = rpc($url, 'resources/list');
check('resources/list : liste vide + cache',
    ($r['result']['resources'] ?? null) === [] && isset($r['result']['ttlMs'], $r['result']['cacheScope']));

[, $r] = rpc($url, 'resources/templates/list');
check('resources/templates/list : liste vide', ($r['result']['resourceTemplates'] ?? null) === []);

[$status, $r] = rpc($url, 'resources/read', ['uri' => 'wp://post/101']);
check('resources/read : erreur -32602 (aucune resource exposée)', ($r['error']['code'] ?? 0) === -32602);

[, $r] = rpc($url, 'prompts/list');
check('prompts/list : liste vide + resultType complete',
    ($r['result']['prompts'] ?? null) === [] && ($r['result']['resultType'] ?? '') === 'complete');

[$status, $r] = rpc($url, 'prompts/get', ['name' => 'rewrite_as', 'arguments' => []]);
check('prompts/get : erreur -32602 (aucun prompt exposé)', ($r['error']['code'] ?? 0) === -32602);

// --- 5. Erreurs protocolaires (le cœur de la révision 2026-07-28) --------------

[$status, $r] = rpc($url, 'methode/inexistante');
check('méthode inconnue : HTTP 404 + erreur -32601', $status === 404 && ($r['error']['code'] ?? 0) === -32601);

[$status, $r] = rpc($url, 'initialize', ['protocolVersion' => '2025-06-18', 'capabilities' => []]);
check('initialize legacy : rejeté en nommant les versions supportées',
    $status === 404 && str_contains($r['error']['message'] ?? '', PROTO));

[$status, $r] = rpc($url, 'tools/list', [], ['drop' => ['MCP-Protocol-Version']]);
check('MCP-Protocol-Version manquant : HTTP 400 + -32020', $status === 400 && ($r['error']['code'] ?? 0) === -32020);

[$status, $r] = rpc($url, 'tools/list', [], ['method' => 'tools/call']);
check('Mcp-Method différent du corps : HTTP 400 + -32020', $status === 400 && ($r['error']['code'] ?? 0) === -32020);

[$status, $r] = rpc($url, 'tools/call', ['name' => 'get_post', 'arguments' => ['id' => 101]], ['name' => 'update_post']);
check('Mcp-Name différent du corps : HTTP 400 + -32020', $status === 400 && ($r['error']['code'] ?? 0) === -32020);

[$status, $r] = rpc($url, 'tools/list', [], ['version' => '1900-01-01', 'metaVersion' => '1900-01-01']);
check('version non supportée : HTTP 400 + -32022 + data.supported',
    $status === 400 && ($r['error']['code'] ?? 0) === -32022
    && in_array(PROTO, $r['error']['data']['supported'] ?? [], true));

[$status, $body] = post($url, '{json invalide', ['MCP-Protocol-Version' => PROTO, 'Mcp-Method' => 'x']);
$r = json_decode($body, true);
check('JSON invalide : HTTP 400 + erreur -32700', $status === 400 && ($r['error']['code'] ?? 0) === -32700);

[$status, $body] = post($url, '{"method": "sans_version"}', ['MCP-Protocol-Version' => PROTO, 'Mcp-Method' => 'sans_version']);
$r = json_decode($body, true);
check('message sans jsonrpc 2.0 : HTTP 400 + erreur -32600', $status === 400 && ($r['error']['code'] ?? 0) === -32600);

// --- Bilan ----------------------------------------------------------------------

printf("\n%s\n", $failures === 0 ? 'Validation complète : tous les tests passent.' : "ÉCHEC : $failures test(s) en erreur.");
exit($failures === 0 ? 0 : 1);

