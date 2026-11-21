<?php
declare(strict_types=1);

/*
 * Konfigurasi EightMedi.
 * Nilai dapat dibaca dari environment server atau file .env di root project.
 * Jangan pernah commit file .env ke repository.
 */
$projectRoot = dirname(__DIR__);
$envFile = $projectRoot . DIRECTORY_SEPARATOR . '.env';

if (is_file($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        if ($name === '' || getenv($name) !== false) {
            continue;
        }
        if (strlen($value) >= 2 && (
            ($value[0] === '"' && str_ends_with($value, '"')) ||
            ($value[0] === "'" && str_ends_with($value, "'"))
        )) {
            $value = substr($value, 1, -1);
        }
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
    }
}

$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? 'localhost'));
$isLocalHost = in_array(preg_replace('/:\d+$/', '', $host), ['localhost', '127.0.0.1', '::1'], true);
$defaultEnvironment = $isLocalHost ? 'local' : 'production';

$env = static function (string $key, ?string $default = null): ?string {
    $value = getenv($key);
    return $value === false ? $default : (string) $value;
};

$appEnvironment = $env('APP_ENV', $defaultEnvironment) ?? $defaultEnvironment;
$isLocalEnvironment = $appEnvironment === 'local';

define('APP_NAME', 'EightMedi');
define('APP_SUBTITLE', 'Sistem Informasi Manajemen Farmasi');
define('APP_ENV', $appEnvironment);
define('APP_BASE_URL', rtrim((string) $env('APP_BASE_URL', $isLocalEnvironment ? '/simrs-farmasi' : ''), '/'));

define('DB_HOST', $env('DB_HOST', $isLocalEnvironment ? '127.0.0.1' : '') ?? '');
define('DB_NAME', $env('DB_NAME', $isLocalEnvironment ? 'simrs_farmasi' : '') ?? '');
define('DB_USER', $env('DB_USER', $isLocalEnvironment ? 'root' : '') ?? '');
define('DB_PASS', $env('DB_PASS', $isLocalEnvironment ? '' : '') ?? '');
define('DB_CHARSET', $env('DB_CHARSET', 'utf8mb4') ?? 'utf8mb4');

$idleTimeout = (int) ($env('SESSION_IDLE_TIMEOUT', '1800') ?? '1800');
define('APP_SESSION_IDLE_TIMEOUT', max(600, min(7200, $idleTimeout)));
define('APP_SESSION_MAX_LIFETIME', 28800);

if (!$isLocalEnvironment && (DB_HOST === '' || DB_NAME === '' || DB_USER === '')) {
    throw new RuntimeException('Konfigurasi database production belum lengkap. Periksa file .env.');
}
