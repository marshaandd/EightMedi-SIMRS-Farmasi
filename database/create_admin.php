<?php
declare(strict_types=1);

/*
 * CLI-only helper to create the first production administrator.
 * Never expose this script through a browser.
 *
 * Windows CMD example:
 *   set EIGHTMEDI_ADMIN_NAME=Administrator
 *   set EIGHTMEDI_ADMIN_USERNAME=admin
 *   set EIGHTMEDI_ADMIN_PASSWORD=use-a-unique-strong-password
 *   php database/create_admin.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit("Not found\n");
}

require_once dirname(__DIR__) . '/app/config.php';
require_once dirname(__DIR__) . '/app/database.php';

$name = trim((string) getenv('EIGHTMEDI_ADMIN_NAME'));
$username = trim((string) getenv('EIGHTMEDI_ADMIN_USERNAME'));
$password = (string) getenv('EIGHTMEDI_ADMIN_PASSWORD');

if ($name === '' || mb_strlen($name, 'UTF-8') > 120
    || !preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
    fwrite(STDERR, "Set EIGHTMEDI_ADMIN_NAME and a valid EIGHTMEDI_ADMIN_USERNAME.\n");
    exit(1);
}
if (strlen($password) < 12
    || !preg_match('/[a-z]/', $password)
    || !preg_match('/[A-Z]/', $password)
    || !preg_match('/[0-9]/', $password)
    || !preg_match('/[^A-Za-z0-9]/', $password)) {
    fwrite(STDERR, "Password must be at least 12 characters and include upper/lowercase, number, and symbol.\n");
    exit(1);
}

$pdo = db();
$adminCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'ADMIN' AND is_active = 1")->fetchColumn();
if ($adminCount > 0) {
    fwrite(STDERR, "An active administrator already exists. Use the account-management page instead.\n");
    exit(1);
}

$statement = $pdo->prepare(
    'INSERT INTO users (full_name, username, password_hash, role, must_change_password, is_active)
     VALUES (:full_name, :username, :password_hash, :role, 0, 1)'
);
$statement->execute([
    'full_name' => $name,
    'username' => $username,
    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
    'role' => 'ADMIN',
]);

fwrite(STDOUT, "Administrator account created successfully.\n");
