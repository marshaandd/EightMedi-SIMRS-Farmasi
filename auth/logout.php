<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('dashboard.php');
}

$token = $_POST['csrf_token'] ?? null;
if (!csrf_is_valid($token)) {
    set_flash('error', 'Permintaan logout tidak valid. Silakan coba lagi.');
    redirect('dashboard.php');
}

auth_clear_session();
header('Location: ' . rtrim(APP_BASE_URL, '/') . '/auth/login.php');
exit;
