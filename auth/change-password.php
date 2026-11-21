<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';
require_login();

$pdo = db();
$user = current_user();
$errors = [];
$flash = get_flash();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_is_valid($_POST['csrf_token'] ?? null)) {
        http_response_code(419);
        exit('Sesi formulir tidak valid. Muat ulang halaman lalu coba kembali.');
    }

    $currentPassword = (string) ($_POST['current_password'] ?? '');
    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');

    try {
        $statement = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
        $statement->execute(['id' => (int) $user['id']]);
        $storedHash = $statement->fetchColumn();

        if (!is_string($storedHash) || !password_verify($currentPassword, $storedHash)) {
            $errors[] = 'Password saat ini tidak sesuai.';
        } elseif ($newPassword === $currentPassword) {
            $errors[] = 'Password baru harus berbeda dari password saat ini.';
        } elseif (($policyError = password_policy_error($newPassword)) !== null) {
            $errors[] = $policyError;
        } elseif (!hash_equals($newPassword, $confirmPassword)) {
            $errors[] = 'Konfirmasi password baru tidak sama.';
        } else {
            $update = $pdo->prepare(
                'UPDATE users SET password_hash = :hash, must_change_password = 0 WHERE id = :id'
            );
            $update->execute([
                'hash' => password_hash($newPassword, PASSWORD_DEFAULT),
                'id' => (int) $user['id'],
            ]);
            session_regenerate_id(true);
            $_SESSION['auth_user']['must_change_password'] = 0;
            $_SESSION['last_activity'] = time();
            set_flash('success', 'Password berhasil diperbarui.');
            redirect('dashboard.php');
        }
    } catch (PDOException $exception) {
        error_log($exception->getMessage());
        $errors[] = 'Password gagal diperbarui. Silakan coba kembali.';
    }
}
?>
<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#f7dbe9">
    <title>Ubah password · <?= e(APP_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/app.css">
    <link rel="stylesheet" href="../assets/css/theme.css?v=1">
    <link rel="stylesheet" href="../assets/css/security.css">
</head>
<body class="app-body">
<header class="app-header">
    <a class="brand" href="../dashboard.php"><span class="brand-mark"><span></span><i></i></span><span class="brand-name">Eight<span>Medi</span></span></a>
    <nav class="main-nav" aria-label="Navigasi utama">
        <a href="../dashboard.php">Ringkasan</a><a href="../master/index.php">Master data</a><a href="../pelayanan/index.php">Pelayanan resep</a><a href="../persediaan/index.php">Persediaan</a><a href="../laporan/index.php">Laporan</a>
        <?php if (($user['role'] ?? '') === 'ADMIN'): ?><a href="users.php">Pengguna</a><?php endif; ?>
    </nav>
    <div class="header-account"><div class="account-copy"><strong><?= e($user['full_name'] ?? 'Pengguna') ?></strong><span>Ubah password</span></div><div class="avatar"><?= e(mb_strtoupper(mb_substr((string) ($user['full_name'] ?? 'U'), 0, 1))) ?></div><form method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><button class="logout-button" type="submit" title="Keluar" aria-label="Keluar">Keluar</button></form></div>
</header>
<main class="dashboard-main security-main">
    <div class="security-breadcrumb"><a href="../dashboard.php">Ringkasan</a><span>/</span><strong>Ubah password</strong></div>
    <section class="security-title"><div><p class="section-kicker">KEAMANAN AKUN</p><h1><?= (int) ($user['must_change_password'] ?? 0) === 1 ? 'Buat password baru' : 'Ubah password' ?></h1><p>Gunakan password unik yang tidak dipakai pada layanan lain.</p></div></section>
    <?php if ($flash): ?><div class="security-alert success"><?= e($flash['message'] ?? '') ?></div><?php endif; ?>
    <?php if ($errors): ?><div class="security-alert error"><?php foreach ($errors as $error): ?><p><?= e($error) ?></p><?php endforeach; ?></div><?php endif; ?>
    <section class="security-card password-card">
        <form method="post" class="security-form password-form">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <label><span>Password saat ini</span><input type="password" name="current_password" autocomplete="current-password" required></label>
            <label><span>Password baru</span><input type="password" name="new_password" minlength="12" autocomplete="new-password" required><small>Minimal 12 karakter, mengandung huruf besar, huruf kecil, angka, dan simbol.</small></label>
            <label><span>Konfirmasi password baru</span><input type="password" name="confirm_password" minlength="12" autocomplete="new-password" required></label>
            <div class="security-form-actions"><button class="primary-button" type="submit">Simpan password baru</button><?php if ((int) ($user['must_change_password'] ?? 0) !== 1): ?><a class="quiet-button" href="../dashboard.php">Kembali</a><?php endif; ?></div>
        </form>
    </section>
    <footer class="dashboard-footer"><span>EightMedi · SIMRS Farmasi</span><span>Keamanan akun</span></footer>
</main>
</body>
</html>
