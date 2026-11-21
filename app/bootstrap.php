<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';

$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';

ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', (string) APP_SESSION_IDLE_TIMEOUT);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('eightmedi_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => (APP_BASE_URL === '' ? '/' : APP_BASE_URL . '/'),
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/helpers.php';
