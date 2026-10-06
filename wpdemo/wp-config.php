<?php

/*
 * Configuration du WordPress de démo (conférence Forum PHP 2026).
 * Base : wpdemo, utilisateur wpdemo/wpdemo (local uniquement).
 * Lancement : php -S 127.0.0.1:8081  (depuis ce dossier)
 */

define('DB_NAME', 'wpdemo');
define('DB_USER', 'wpdemo');
define('DB_PASSWORD', 'wpdemo');
define('DB_HOST', '127.0.0.1');
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');

define('AUTH_KEY',         'a0d23f8497f42ca45d1133a74a7cd7a127adfea27bb2aecfc525aba42c6809c0');
define('SECURE_AUTH_KEY',  'a795ce3cc200baca14c81c4192c819dd9e6b3302c64c2fe683b0701e3dd7be74');
define('LOGGED_IN_KEY',    'd9fe69833369c539d90ad8cd93a0bf43aaf07e60ce8fbf14a1ea6f892fa105fc');
define('NONCE_KEY',        '5a1eec40676db86d368b70f1a081aced491d480538fe8a87a5207d7d5e2f00c3');
define('AUTH_SALT',        '4210a4403f08b234305433d87113645b483c8d82f1bbb4cb0363345f62f4ff2b');
define('SECURE_AUTH_SALT', 'bd37edb85d25cb6a7d214ce8b45ee0df527ebe30e52af5f74f64dc16e4cb6e66');
define('LOGGED_IN_SALT',   '02dbac9820637c616626a3223fa741af2110a05f59c04f32f698e53764c48a89');
define('NONCE_SALT',       '6bce3d0d3f23de19535cb7e53d99c5d09c91d55ddfab07447efe8d2bf1ae9082');

$table_prefix = 'wp_';

// URL fixe : la démo tourne sur php -S 127.0.0.1:8081
define('WP_HOME',    'http://127.0.0.1:8081');
define('WP_SITEURL', 'http://127.0.0.1:8081');

// Démo : pas de mises à jour automatiques, pas de cache, debug coupé
define('AUTOMATIC_UPDATER_DISABLED', true);
define('WP_DEBUG', false);

if (!defined('ABSPATH'))
    define('ABSPATH', __DIR__ . '/');

require_once ABSPATH . 'wp-settings.php';
