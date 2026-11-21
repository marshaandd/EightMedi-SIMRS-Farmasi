<?php
declare(strict_types=1);

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirect(string $path): never
{
    $base = rtrim(APP_BASE_URL, '/');
    $target = $base . '/' . ltrim($path, '/');
    header('Location: ' . $target);
    exit;
}

function current_user(): ?array
{
    return isset($_SESSION['auth_user']) && is_array($_SESSION['auth_user'])
        ? $_SESSION['auth_user']
        : null;
}

function auth_clear_session(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

function auth_expire_session(string $message): never
{
    auth_clear_session();
    session_id('');
    session_start();
    session_regenerate_id(true);
    set_flash('error', $message);
    redirect('auth/login.php');
}

function require_login(): void
{
    $user = current_user();
    if (!$user || empty($user['id'])) {
        redirect('auth/login.php');
    }

    $now = time();
    $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
    $authStarted = (int) ($_SESSION['auth_started_at'] ?? 0);

    if (
        $lastActivity === 0
        || $authStarted === 0
        || ($now - $lastActivity) > APP_SESSION_IDLE_TIMEOUT
        || ($now - $authStarted) > APP_SESSION_MAX_LIFETIME
    ) {
        auth_expire_session('Sesi telah berakhir. Silakan masuk kembali.');
    }

    try {
        $statement = db()->prepare('SELECT is_active, role, must_change_password FROM users WHERE id = :id LIMIT 1');
        $statement->execute(['id' => (int) $user['id']]);
        $account = $statement->fetch();
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        http_response_code(503);
        exit('Layanan autentikasi sementara tidak tersedia. Silakan coba kembali.');
    }

    if (!$account || (int) $account['is_active'] !== 1) {
        auth_expire_session('Akun tidak aktif atau tidak ditemukan. Hubungi administrator.');
    }

    $_SESSION['auth_user']['role'] = (string) $account['role'];
    $_SESSION['auth_user']['must_change_password'] = (int) $account['must_change_password'];
    $_SESSION['last_activity'] = $now;

    if (!headers_sent()) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }

    $script = str_replace('\\', '/', (string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    $isPasswordChangePage = str_ends_with($script, '/auth/change-password.php');
    if ((int) $account['must_change_password'] === 1 && !$isPasswordChangePage) {
        redirect('auth/change-password.php');
    }
}

function require_role(string $role): void
{
    require_login();
    $user = current_user();
    if (strtoupper((string) ($user['role'] ?? 'PETUGAS')) !== strtoupper($role)) {
        http_response_code(403);
        exit('Anda tidak memiliki izin untuk mengakses halaman ini.');
    }
}

function csrf_token(): string
{
    if (!isset($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_is_valid(mixed $token): bool
{
    return is_string($token)
        && isset($_SESSION['csrf_token'])
        && is_string($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function set_flash(string $type, string $message): void
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function get_flash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return is_array($flash) ? $flash : null;
}

/**
 * Kunci rate limit menggunakan hash, bukan menyimpan alamat IP mentah.
 * Batas: 5 percobaan per akun+IP dan 20 percobaan per IP dalam 15 menit.
 */
function auth_login_attempt_keys(string $username): array
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $ip = substr($ip, 0, 64);
    $normalizedUsername = mb_strtolower(trim($username), 'UTF-8');

    return [
        'ip' => hash('sha256', 'ip|' . $ip),
        'account' => hash('sha256', 'account|' . $normalizedUsername . '|' . $ip),
    ];
}

function auth_login_is_limited(array $keys): bool
{
    $statement = db()->prepare(
        'SELECT 1 FROM auth_login_attempts
         WHERE attempt_key IN (:ip_key, :account_key)
           AND locked_until IS NOT NULL
           AND locked_until > NOW()
         LIMIT 1'
    );
    $statement->execute([
        'ip_key' => $keys['ip'],
        'account_key' => $keys['account'],
    ]);
    return (bool) $statement->fetchColumn();
}

function auth_login_record_failure(array $keys): void
{
    $statement = db()->prepare(
        'INSERT INTO auth_login_attempts
            (attempt_key, attempts, window_started_at, locked_until, updated_at)
         VALUES (:attempt_key, 1, NOW(), NULL, NOW())
         ON DUPLICATE KEY UPDATE
            locked_until = CASE
                WHEN window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
                    THEN CASE WHEN 1 >= :limit_reset THEN DATE_ADD(NOW(), INTERVAL 15 MINUTE) ELSE NULL END
                WHEN attempts + 1 >= :limit_increment
                    THEN DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                ELSE locked_until
            END,
            attempts = CASE
                WHEN window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE) THEN 1
                ELSE attempts + 1
            END,
            window_started_at = CASE
                WHEN window_started_at < DATE_SUB(NOW(), INTERVAL 15 MINUTE) THEN NOW()
                ELSE window_started_at
            END,
            updated_at = NOW()'
    );

    $statement->execute([
        'attempt_key' => $keys['ip'],
        'limit_reset' => 20,
        'limit_increment' => 20,
    ]);
    $statement->execute([
        'attempt_key' => $keys['account'],
        'limit_reset' => 5,
        'limit_increment' => 5,
    ]);

    // Bersihkan kunci lama secara berkala tanpa menyimpan alamat IP asli.
    db()->exec("DELETE FROM auth_login_attempts WHERE updated_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
}

function auth_login_clear_attempts(array $keys): void
{
    $statement = db()->prepare('DELETE FROM auth_login_attempts WHERE attempt_key IN (:ip_key, :account_key)');
    $statement->execute([
        'ip_key' => $keys['ip'],
        'account_key' => $keys['account'],
    ]);
}

function password_policy_error(string $password): ?string
{
    if (strlen($password) < 12) {
        return 'Password minimal 12 karakter.';
    }
    if (!preg_match('/[a-z]/', $password) || !preg_match('/[A-Z]/', $password)
        || !preg_match('/[0-9]/', $password) || !preg_match('/[^A-Za-z0-9]/', $password)) {
        return 'Password harus mengandung huruf besar, huruf kecil, angka, dan simbol.';
    }
    return null;
}
