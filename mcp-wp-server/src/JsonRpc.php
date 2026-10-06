<?php

namespace Mcp;

/**
 * Encodage et décodage des messages JSON-RPC 2.0.
 * MCP utilise JSON-RPC comme couche de message : requêtes (avec id),
 * notifications (sans id), réponses (result ou error).
 */
final class JsonRpc {
    public const PARSE_ERROR          = -32700;
    public const INVALID_REQUEST      = -32600;
    public const METHOD_NOT_FOUND     = -32601;
    public const INVALID_PARAMS       = -32602;
    public const INTERNAL_ERROR       = -32603;
    public const HEADER_MISMATCH      = -32020; // codes réservés MCP (-32020..-32099)
    public const UNSUPPORTED_PROTOCOL = -32022;

    /** Décode un corps de requête ; retourne null si le JSON est invalide. */
    public static function decode(string $body): ?array {
        $msg = json_decode($body, true);
        return is_array($msg) ? $msg : null;
    }

    /** Le message est-il une requête ou notification JSON-RPC 2.0 valide ? */
    public static function isValid(array $msg): bool {
        return ($msg['jsonrpc'] ?? null) === '2.0' && is_string($msg['method'] ?? null);
    }

    /** Une notification est une requête sans id : elle n'attend pas de réponse. */
    public static function isNotification(array $msg): bool {
        return !array_key_exists('id', $msg);
    }

    public static function success(mixed $id, array $result): array {
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    public static function error(mixed $id, int $code, string $message, ?array $data = null): array {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null)
            $error['data'] = $data;
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error];
    }

    /** Code d'erreur JSON-RPC correspondant à une exception du dispatch. */
    public static function codeFor(\Throwable $e): int {
        return match (true) {
            $e instanceof \BadFunctionCallException => self::METHOD_NOT_FOUND,
            $e instanceof \InvalidArgumentException,
            $e instanceof \DomainException          => self::INVALID_PARAMS,
            default                                 => self::INTERNAL_ERROR,
        };
    }
}

