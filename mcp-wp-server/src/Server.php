<?php

namespace Mcp;

/**
 * Serveur MCP (révision de protocole 2026-07-28, stateless).
 * Chaque requête est un POST HTTP autoportant : la version du protocole et
 * l'identité du client voyagent dans les en-têtes et dans _meta ; il n'y a
 * ni handshake, ni session. Exactement le modèle d'exécution de PHP.
 *
 * Les capacités (tools, resources, prompts) sont fournies sous forme d'un
 * tableau associatif : les parties descriptives alimentent la discovery,
 * les closures sont exécutées à l'appel. Voir src/capabilities.php.
 */
final class Server {
    private const PROTOCOL_VERSION = '2026-07-28';
    private const ENDPOINT = '/mcp';
    private const META_VERSION = 'io.modelcontextprotocol/protocolVersion';
    private const META_SERVER  = 'io.modelcontextprotocol/serverInfo';

    public function __construct(private readonly array $capabilities, private readonly array $config) {}

    /** Traite la requête HTTP courante et émet la réponse. */
    public function handle(): void {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if ($path !== self::ENDPOINT) {
            $this->respond(404, null);
        } elseif (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST'); // GET et DELETE datent des révisions à session : 405
            $this->respond(405, null);
        } elseif (!$this->originAllowed()) {
            $this->respond(403, null); // protection contre le DNS rebinding
        } elseif (!$this->authorized()) {
            $this->respond(401, null);
        } else {
            $this->handleMessage((string) file_get_contents('php://input'));
        }
    }

    /** Décode, valide et exécute un message JSON-RPC autoportant. */
    private function handleMessage(string $body): void {
        $msg = JsonRpc::decode($body);
        if ($msg === null) {
            $this->respond(400, JsonRpc::error(null, JsonRpc::PARSE_ERROR, 'Parse error'));
            return;
        }
        if (!JsonRpc::isValid($msg)) {
            $this->respond(400, JsonRpc::error($msg['id'] ?? null, JsonRpc::INVALID_REQUEST, 'Invalid Request'));
            return;
        }
        if (JsonRpc::isNotification($msg)) {
            $this->respond(202, null); // une notification n'attend pas de réponse
            return;
        }
        $id = $msg['id'];
        if (($fault = $this->headerFault($msg)) !== null) {
            // les en-têtes miroirs (routage sans lire le corps) doivent coller au corps
            $this->respond(400, JsonRpc::error($id, JsonRpc::HEADER_MISMATCH, $fault));
            return;
        }
        if (($_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? '') !== self::PROTOCOL_VERSION) {
            $this->respond(400, JsonRpc::error($id, JsonRpc::UNSUPPORTED_PROTOCOL, 'Unsupported protocol version',
                ['supported' => [self::PROTOCOL_VERSION], 'requested' => $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? '']));
            return;
        }
        try {
            $result = $this->dispatch($msg['method'], $msg['params'] ?? []);
            // décoration commune à tous les résultats de la révision 2026-07-28
            $result += ['resultType' => 'complete', '_meta' => [self::META_SERVER => $this->config['server']]];
            $this->respond(200, JsonRpc::success($id, $result));
        } catch (\BadFunctionCallException $e) {
            // méthode inconnue : 404 + -32601 (distingue un serveur moderne d'un 404 legacy)
            $this->respond(404, JsonRpc::error($id, JsonRpc::METHOD_NOT_FOUND, $e->getMessage()));
        } catch (\Throwable $e) {
            $this->respond(200, JsonRpc::error($id, JsonRpc::codeFor($e), $e->getMessage()));
        }
    }

    /** Routage des méthodes MCP. */
    private function dispatch(string $method, array $params): array {
        return match ($method) {
            // discovery
            'server/discover'          => $this->discover(),
            // tools
            'tools/list'               => ['tools' => $this->toolList()] + $this->cacheable(),
            'tools/call'               => $this->callTool($params),
            // resources
            'resources/list'           => ['resources' => $this->resourceList(templates: false)] + $this->cacheable(),
            'resources/templates/list' => ['resourceTemplates' => $this->resourceList(templates: true)] + $this->cacheable(),
            'resources/read'           => $this->readResource($params),
            // prompts
            'prompts/list'             => ['prompts' => $this->promptList()] + $this->cacheable(),
            'prompts/get'              => $this->getPrompt($params),
            // ancienne version du protocole
            'initialize'               => throw new \BadFunctionCallException(
                'Pas de handshake depuis la révision 2026-07-28. Versions supportées : ' . self::PROTOCOL_VERSION
            ),
            // erreur
            default => throw new \BadFunctionCallException("Méthode inconnue : $method"),
        };
    }

    /** server/discover : versions, capacités et identité, à la demande du client. */
    private function discover(): array {
        return [
            'supportedVersions' => [self::PROTOCOL_VERSION],
            'capabilities'      => ['tools' => new \StdClass()],
            'instructions'      => 'Serveur branché sur un blog WordPress : lecture, recherche et mise à jour des articles.',
            'ttlMs'             => 3600000,
            'cacheScope'        => 'public',
        ];
    }

    /** Descriptions des tools pour la discovery : tout sauf la closure. */
    private function toolList(): array {
        $list = [];
        foreach ($this->capabilities['tools'] as $name => $tool)
            $list[] = ['name' => $name] + array_diff_key($tool, ['handler' => 0]);
        return $list;
    }

    private function callTool(array $params): array {
        $tool = $this->capabilities['tools'][$params['name'] ?? '']
            ?? throw new \InvalidArgumentException('Tool inconnu : ' . ($params['name'] ?? ''));
        try {
            // Le handler rend des données ; le protocole veut du texte dans content.
            $text = json_encode(($tool['handler'])($params['arguments'] ?? []), JSON_UNESCAPED_UNICODE);
            return [
                'content' => [[
                    'type' => 'text',
                    'text' => $text,
                ]],
                'isError' => false,
            ];
        } catch (\Throwable $e) {
            // Erreur d'exécution : signalée DANS le résultat (isError), pas au
            // niveau JSON-RPC, pour que le LLM puisse la lire et réagir.
            return ['content' => [['type' => 'text', 'text' => 'Erreur : ' . $e->getMessage()]], 'isError' => true];
        }
    }

    /** Resources à URI fixe (resources/list) ou paramétrée (templates/list). */
    private function resourceList(bool $templates): array {
        $list = array_filter($this->capabilities['resources'] ?? [],
            fn (array $r): bool => isset($r['uriTemplate']) === $templates);
        return array_values(array_map(fn (array $r): array => array_diff_key($r, ['read' => 0, 'pattern' => 0]), $list));
    }

    private function readResource(array $params): array {
        $uri = (string) ($params['uri'] ?? '');
        foreach ($this->capabilities['resources'] ?? [] as $resource) {
            if (($resource['uri'] ?? null) === $uri
                || (isset($resource['pattern']) && preg_match($resource['pattern'], $uri) === 1)) {
                return ['contents' => [[
                    'uri' => $uri,
                    'mimeType' => $resource['mimeType'],
                    'text' => ($resource['read'])($uri),
                ]]] + $this->cacheable(30000, 'private');
            }
        }
        throw new \DomainException("Resource inconnue : $uri");
    }

    /** Descriptions des prompts pour la discovery : tout sauf la closure. */
    private function promptList(): array {
        $list = [];
        foreach ($this->capabilities['prompts'] ?? [] as $name => $prompt)
            $list[] = ['name' => $name, 'description' => $prompt['description'], 'arguments' => $prompt['arguments']];
        return $list;
    }

    private function getPrompt(array $params): array {
        $prompt = ($this->capabilities['prompts'] ?? [])[$params['name'] ?? '']
            ?? throw new \InvalidArgumentException('Prompt inconnu : ' . ($params['name'] ?? ''));
        return ($prompt['get'])($params['arguments'] ?? []);
    }

    /** Champs de cache requis sur les résultats de listes et de lectures. */
    private function cacheable(int $ttlMs = 300000, string $scope = 'public'): array {
        return ['ttlMs' => $ttlMs, 'cacheScope' => $scope];
    }

    /**
     * Validation des en-têtes miroirs (MCP-Protocol-Version, Mcp-Method,
     * Mcp-Name) : ils permettent aux intermédiaires de router sans lire le
     * corps, à condition de correspondre exactement au corps.
     */
    private function headerFault(array $msg): ?string {
        $version = $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] ?? '';
        if ($version === '')
            return 'En-tête MCP-Protocol-Version manquant.';
        $metaVersion = $msg['params']['_meta'][self::META_VERSION] ?? null;
        if ($metaVersion !== null && $metaVersion !== $version)
            return 'MCP-Protocol-Version ne correspond pas à _meta.';
        if (($_SERVER['HTTP_MCP_METHOD'] ?? '') !== $msg['method'])
            return 'Mcp-Method absent ou différent de la méthode du corps.';
        if (in_array($msg['method'], ['tools/call', 'resources/read', 'prompts/get'], true)) {
            $name = $msg['params']['name'] ?? $msg['params']['uri'] ?? '';
            if (($_SERVER['HTTP_MCP_NAME'] ?? '') !== $name)
                return 'Mcp-Name absent ou différent du corps.';
        }
        return null;
    }

    /** L'en-tête Origin, quand il est présent, doit désigner la machine locale. */
    private function originAllowed(): bool {
        $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
        return $origin === '' || in_array(parse_url($origin, PHP_URL_HOST), ['127.0.0.1', 'localhost'], true);
    }

    /** Vérification du jeton Bearer (aucune si auth_token est vide : démo locale). */
    private function authorized(): bool {
        $token = (string) ($this->config['auth_token'] ?? '');
        return $token === '' || hash_equals("Bearer $token", $_SERVER['HTTP_AUTHORIZATION'] ?? '');
    }

    private function respond(int $status, ?array $body): void {
        http_response_code($status);
        if ($body !== null) {
            header('Content-Type: application/json');
            echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
    }
}

