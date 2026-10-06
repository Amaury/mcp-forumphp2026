<?php

/*
 * Configuration locale (base wpdemo créée le 2026-09-02).
 * Voir config.php.dist pour le modèle et README.md pour l'utilisateur
 * SQL à droits limités recommandé pour la démo.
 */

return [
    'db' => [
        'dsn'      => 'mysql:host=127.0.0.1;port=3306;dbname=wpdemo;charset=utf8mb4',
        'user'     => 'wpdemo',
        'password' => 'wpdemo',
    ],

    // Vide : pas d'authentification (démo locale uniquement).
    'auth_token' => '',

    'server' => [
        'name'    => 'wp-mcp-server',
        'version' => '1.0.0',
    ],
];

