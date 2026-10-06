<?php

/*
 * Point d'entrée du serveur MCP.
 * Lancement : php -S 127.0.0.1:8080 public/index.php
 * Le serveur répond sur POST /mcp (transport Streamable HTTP).
 */

// Autoloader minimaliste : Mcp\Foo\Bar => src/Foo/Bar.php
spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'Mcp\\')) {
        require __DIR__ . '/../src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    }
});

$config = require __DIR__ . '/../config.php';

$pdo = new PDO($config['db']['dsn'], $config['db']['user'], $config['db']['password'], [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
$dao = new Mcp\Wp\PostDao($pdo);

// La carte du serveur : 3 tools, 1 resource, 1 prompt (voir src/capabilities.php)
$capabilities = require __DIR__ . '/../src/capabilities.php';

(new Mcp\Server($capabilities, $config))->handle();

